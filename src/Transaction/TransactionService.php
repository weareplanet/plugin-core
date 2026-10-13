<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodCollection;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodSorting;
use WeArePlanet\PluginCore\SharedKernel\RetryableOperation;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionNotConfirmableException;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionTotalNegativeException;

#[LogContext(domain: 'transaction', subdomain: 'checkout')]
class TransactionService
{
    use DomainLoggerTrait;

    /**
     * Number of times a confirmation is attempted while it keeps failing with a retryable error.
     */
    private const CONFIRM_ATTEMPTS = 3;

    /**
     * Base pause between retry attempts, in milliseconds.
     */
    private const RETRY_DELAY_MS = 400;

    /**
     * Number of times an update is attempted while it keeps conflicting with another request.
     */
    private const UPDATE_ATTEMPTS = 5;

    public function __construct(
        private readonly TransactionGatewayInterface $gateway,
        private readonly LineItemConsistencyService $consistencyService,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * Confirms a PENDING transaction.
     *
     * This only confirms; it does not change the transaction's data. A shop that has to send its
     * final order data first runs {@see upsert()} right before, and checks that the returned
     * transaction has the ID it holds: a different ID means the transaction could not be updated
     * and a new one was created, which must not be confirmed in its place.
     *
     * A transaction that is already CONFIRMED is returned as it is, which makes repeating the call
     * safe. This includes the case where a confirmation went through but its response was lost.
     * The transaction is read again before every attempt, and a retryable failure, such as a lost
     * connection, is retried a few times.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return Transaction The confirmed transaction, read again after the confirmation.
     * @throws TransactionNotConfirmableException If the transaction is neither PENDING nor CONFIRMED.
     * @throws TransactionException If the confirmation fails, with the last failure once the attempts run out.
     */
    public function confirmTransaction(int $spaceId, int $transactionId): Transaction
    {
        return $this->retrying(self::CONFIRM_ATTEMPTS)->run(
            function () use ($spaceId, $transactionId): Transaction {
                $transaction = $this->gateway->get($spaceId, $transactionId);

                if ($transaction->state === State::CONFIRMED) {
                    return $transaction;
                }

                if ($transaction->state !== State::PENDING) {
                    throw new TransactionNotConfirmableException(
                        $transaction->state,
                        "Transaction {$transactionId} in space {$spaceId} is {$transaction->state->value} and cannot be confirmed.",
                        new LocalizedString("Transaction could not be confirmed."),
                    );
                }

                $this->gateway->confirm($spaceId, $transactionId);

                return $this->gateway->get($spaceId, $transactionId);
            },
            null,
            function (\Throwable $e, int $attempt) use ($spaceId, $transactionId): void {
                $this->logger->debug("Transaction confirmation failed with a retryable error.", [
                    'transactionId' => $transactionId,
                    'spaceId' => $spaceId,
                    'attempt' => $attempt,
                    'reason' => $e->getMessage(),
                ]);
            },
        );
    }

    /**
     * Creates a new transaction.
     *
     * @param TransactionContext $context The transaction context.
     * @return Transaction The created transaction.
     * @throws TransactionException If creation fails.
     */
    public function createTransaction(TransactionContext $context): Transaction
    {
        try {
            $this->logger->debug("Creating new transaction.", [
                'merchantReference' => $context->merchantReference,
                'spaceId' => $context->spaceId,
            ]);

            if (($context->expectedGrandTotal ?? 0.0) < -0.00000001) {
                $context->lineItems = $this->consistencyService->sanitizeNegativeLineItems($context->lineItems->all());
                $context->expectedGrandTotal = 0.0;
            }

            $context->lineItems = $this->consistencyService->ensureConsistency(
                $context->lineItems->all(),
                $context->expectedGrandTotal,
                $context->currencyCode,
                $context->spaceId,
                $context->transactionId,
            );

            $this->validateContext($context);

            $result = $this->gateway->create($context);

            $this->logger->debug("Transaction created.", [
                'transactionId' => $result->id ?? null,
                'state' => $result->state->value,
                'spaceId' => $context->spaceId,
            ]);
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("Transaction creation failed.", [
                'spaceId' => $context->spaceId,
                'exception' => $e,
            ]);
            if ($e instanceof TransactionException) {
                throw $e;
            }
            throw new TransactionException(
                "Unable to create transaction in space {$context->spaceId}: " . $e->getMessage(),
                new LocalizedString("Unable to create transaction."),
                $e,
            );
        }
    }

    /**
     * Gets available payment methods for a transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @param PaymentMethodSorting $sortBy The sorting criteria.
     * @return PaymentMethodCollection The available payment methods.
     */
    public function getAvailablePaymentMethods(int $spaceId, int $transactionId, PaymentMethodSorting $sortBy = PaymentMethodSorting::DEFAULT): PaymentMethodCollection
    {
        $this->logger->debug("Fetching available payment methods.", [
            'transactionId' => $transactionId,
            'spaceId' => $spaceId,
        ]);

        $methods = $this->gateway->getAvailablePaymentMethods($spaceId, $transactionId)->all();

        if ($sortBy === PaymentMethodSorting::NAME) {
            $this->logger->debug("Sorting payment methods by name.");
            usort($methods, function (PaymentMethod $a, PaymentMethod $b) {
                // Primary: merchant-configured display order
                $orderComparison = $a->sortOrder <=> $b->sortOrder;
                if ($orderComparison !== 0) {
                    return $orderComparison;
                }

                // Secondary tie-breaker: alphabetical by default title
                return strcasecmp($a->title->getDefault(), $b->title->getDefault());
            });
        }

        $this->logger->debug("Found payment methods.", [
            'count' => count($methods),
            'transactionId' => $transactionId,
            'spaceId' => $spaceId,
        ]);

        return new PaymentMethodCollection(...$methods);
    }

    /**
     * Gets the user-facing failure message for a transaction.
     *
     * Returns the transaction's userFailureMessage when present, otherwise the provided default.
     * Any error during retrieval is logged and the default message is returned, so this method
     * is safe to call from frontend controllers without additional try/catch handling.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @param string $shopLocale The shop's locale (e.g. 'de-DE' or 'en_US') for resolving the localized message.
     * @param string $defaultMessage Returned when the transaction has no failure message or fetching fails.
     * @return string
     */
    public function getFailureMessage(int $spaceId, int $transactionId, string $shopLocale, string $defaultMessage): string
    {
        try {
            $transaction = $this->getTransaction($spaceId, $transactionId);
            if ($transaction->userFailureMessage !== null) {
                return $transaction->userFailureMessage->localize($shopLocale) ?? $defaultMessage;
            }
        } catch (\Throwable $e) {
            $this->logger->debug("Failed to retrieve failure message.", [
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
                // Swallowed on purpose — the caller falls back to a default message.
                'reason' => $e->getMessage(),
            ]);
        }
        return $defaultMessage;
    }

    /**
     * Gets the latest transactions.
     *
     * @param int $spaceId The space ID.
     * @param int $limit The number of transactions to retrieve (default: 10).
     * @return TransactionCollection The latest transactions.
     */
    public function getLatestTransactions(int $spaceId, int $limit = 10): TransactionCollection
    {
        $criteria = new TransactionSearchCriteria();
        $criteria->limit = $limit;
        $criteria->sortField = 'id';
        $criteria->sortOrder = 'DESC';
        return $this->searchTransactions($spaceId, $criteria);
    }

    /**
     * Gets the payment URL.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return PaymentUrl The payment URL value object.
     */
    public function getPaymentUrl(int $spaceId, int $transactionId): PaymentUrl
    {
        $this->logger->debug("Fetching payment URL.", [
            'transactionId' => $transactionId,
            'spaceId' => $spaceId,
        ]);
        return $this->gateway->getPaymentUrl($spaceId, $transactionId);
    }

    /**
     * Gets a transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return Transaction The transaction.
     */
    public function getTransaction(int $spaceId, int $transactionId): Transaction
    {
        $transaction = $this->gateway->get($spaceId, $transactionId);
        $this->logger->debug("Service: Transaction found.", ['state' => $transaction->state->value]);
        return $transaction;
    }

    /**
     * Waits before the next attempt.
     *
     * @param int $delayMs The pause, in milliseconds.
     */
    protected function pause(int $delayMs): void
    {
        usleep($delayMs * 1000);
    }

    /**
     * Builds the retry loop, pausing through {@see pause()}.
     *
     * @param int $attempts Total number of tries, the first one included.
     */
    private function retrying(int $attempts): RetryableOperation
    {
        return new RetryableOperation(
            $attempts,
            self::RETRY_DELAY_MS,
            fn (int $delayMs) => $this->pause($delayMs),
        );
    }

    /**
     * Searches for transactions matching the criteria.
     *
     * @param int $spaceId The space ID.
     * @param TransactionSearchCriteria $criteria The search criteria.
     * @return TransactionCollection The matching transactions.
     */
    public function searchTransactions(int $spaceId, TransactionSearchCriteria $criteria): TransactionCollection
    {
        return $this->gateway->search($spaceId, $criteria);
    }

    /**
     * Updates the existing PENDING transaction, retrying updates that conflict with another request.
     *
     * Each attempt re-reads the transaction so the update carries its current version.
     *
     * @param TransactionContext $context The desired transaction state.
     * @return Transaction The updated transaction, or the original if the conflict persisted.
     * @throws TransactionException If the transaction is not PENDING / not found.
     * @throws \Throwable If the update fails for any reason other than a conflict.
     */
    private function updatePendingTransaction(TransactionContext $context): Transaction
    {
        // The sanitised context is the same on every attempt, so it is prepared once.
        if (($context->expectedGrandTotal ?? 0.0) < -0.00000001) {
            $context->lineItems = $this->consistencyService->sanitizeNegativeLineItems($context->lineItems->all());
            $context->expectedGrandTotal = 0.0;
        }

        $existingTransaction = null;

        try {
            return $this->retrying(self::UPDATE_ATTEMPTS)->run(
                function () use ($context, &$existingTransaction): Transaction {
                    // READ: Returns a DOMAIN Object (Transaction)
                    $existingTransaction = $this->gateway->find($context->spaceId, $context->transactionId);

                    // CHECK: Use Domain Enum
                    if (!$existingTransaction || $existingTransaction->state !== State::PENDING) {
                        // Throwing here forces the catch block to trigger the fallback
                        throw new TransactionException(
                            "Transaction {$context->transactionId} in space {$context->spaceId} not PENDING or not found.",
                            new LocalizedString("Transaction not PENDING or not found."),
                        );
                    }

                    // WRITE: Pass Primitives (ID and Version)
                    return $this->gateway->update(
                        $existingTransaction->id,
                        $existingTransaction->version,
                        $context,
                    );
                },
                static fn (\Throwable $e): bool => $e instanceof TransactionException && $e->isConflict(),
                function (\Throwable $e, int $attempt) use ($context): void {
                    $this->logger->info("Update conflicted with another request: retrying with fresh version.", [
                        'transactionId' => $context->transactionId,
                        'spaceId' => $context->spaceId,
                        'attempts' => $attempt,
                        'reason' => $e->getMessage(),
                    ]);
                },
            );
        } catch (TransactionException $e) {
            // Anything but a persisting conflict is for the caller to handle.
            if (!$e->isConflict() || $existingTransaction === null) {
                throw $e;
            }

            $this->logger->warning("Update still conflicting with another request after all attempts: keeping existing transaction.", [
                'transactionId' => $existingTransaction->id,
                'spaceId' => $context->spaceId,
                'attempts' => self::UPDATE_ATTEMPTS,
                'reason' => $e->getMessage(),
            ]);

            return $existingTransaction;
        }
    }

    /**
     * Upserts a transaction (Create or Update).
     *
     * An update that conflicts with another request is retried with the transaction's
     * fresh version. If the conflict persists, the existing transaction is kept unchanged.
     *
     * Only if the transaction is no longer PENDING, cannot be found, or the update
     * fails for any other reason does it fall back to creating a fresh one.
     *
     * @param TransactionContext $context The transaction context.
     * @param TransactionPersistenceInterface $persistenceStrategy The persistence strategy.
     * @return Transaction The resulting transaction.
     * @throws TransactionException If upsert fails.
     */
    public function upsert(TransactionContext $context, TransactionPersistenceInterface $persistenceStrategy): Transaction
    {
        $result = null;

        if ($context->transactionId === null) {
            $result = $this->createTransaction($context);
        } else {
            try {
                $result = $this->updatePendingTransaction($context);
            } catch (\Throwable $e) {
                // Fallback Logic
                // The transaction is no longer usable (not PENDING, not found, or the update
                // failed for a reason other than a conflict), so we start a new session.
                $this->logger->notice("Update failed; falling back to CREATE.", [
                    'transactionId' => $context->transactionId,
                    'spaceId' => $context->spaceId,
                    // Expected, self-healing path: the fallback below recovers from it,
                    // so the reason is enough and a raw Throwable would misrepresent it.
                    'reason' => $e->getMessage(),
                ]);
                $context->transactionId = null;
                $result = $this->createTransaction($context);
            }
        }

        // Persistence logic remains the same
        if ($result->id !== $context->transactionId) {
            try {
                $persistenceStrategy->persist($result->id);
                $this->logger->debug("Persisted new transaction via strategy.", [
                    'transactionId' => $result->id,
                ]);
            } catch (\Throwable $e) {
                $this->logger->critical("Transaction created but persistence strategy failed.", [
                    'transactionId' => $result->id,
                    'exception' => $e,
                ]);
                throw new TransactionException(
                    "System Error: Could not save transaction session for transaction {$result->id}: " . $e->getMessage(),
                    new LocalizedString("System Error: Could not save transaction session."),
                    $e,
                );
            }
        }

        return $result;
    }

    /**
     * Validates the transaction context.
     *
     * @param TransactionContext $context The transaction context.
     * @throws TransactionException If validation fails.
     */
    private function validateContext(TransactionContext $context): void
    {
        // Use an epsilon for float comparison logic
        $epsilon = 0.00000001;

        // Check for Negative (Total is significantly less than zero)
        if ($context->expectedGrandTotal < -$epsilon) {
            throw new TransactionTotalNegativeException();
        }
    }
}
