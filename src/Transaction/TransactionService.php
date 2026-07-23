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
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionTotalNegativeException;
use WeArePlanet\PluginCore\Transaction\TransactionGatewayInterface;
use WeArePlanet\PluginCore\Transaction\TransactionSearchCriteria;

/**
 * Manages the lifecycle of payment transactions.
 *
 * This service orchestrates transaction creation, updates, and state retrieval.
 * It ensures that line items remain consistent with the expected totals and handles
 * the idempotent "upsert" logic required for seamless session management in shop integrations.
 */
#[LogContext(domain: 'transaction', subdomain: 'checkout')]
class TransactionService
{
    use DomainLoggerTrait;
    /**
     * @param TransactionGatewayInterface $gateway Interface to the SDK or persistence layer.
     * @param LineItemConsistencyService $consistencyService Ensures totals and taxes match line item sums.
     * @param LoggerInterface $logger The system logger.
     */
    public function __construct(
        private readonly TransactionGatewayInterface $gateway,
        private readonly LineItemConsistencyService $consistencyService,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * Synchronizes a local transaction context with the remote gateway.
     *
     * This method ensures that all line items are sanitized (e.g. handling negative totals)
     * and satisfy the gateway's consistency requirements before transmission.
     *
     * @param TransactionContext $context The transaction settings and line items.
     * @return Transaction The persisted transaction object.
     * @throws TransactionException If the gateway rejects the creation.
     */
    public function createTransaction(TransactionContext $context): Transaction
    {
        try {
            $this->logger->debug("Creating new transaction.", [
                'merchantReference' => $context->merchantReference,
                'spaceId' => $context->spaceId,
            ]);

            // Negative Total Sanitization
            // If the overall total is negative (common with aggressive discounts), we normalize it to zero
            // and adjust line items to satisfy the SDK's non-negative requirement.
            if (isset($context->expectedGrandTotal) && $context->expectedGrandTotal < -0.00000001) {
                $context->lineItems = $this->consistencyService->sanitizeNegativeLineItems($context->lineItems->all());
                $context->expectedGrandTotal = 0.0;
            }

            // Consistency Enforcement
            // Many payment gateways require that the sum of line items exactly matches the grand total.
            // We use the consistency service to handle potential rounding discrepancies.
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
                'transactionId' => $result->id,
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
     * Retrieves and optionally sorts payment methods available for a transaction.
     *
     * Sorting is performed locally to ensure a consistent user experience regardless
     * of the order returned by the remote API.
     *
     * @param int $spaceId The identity space.
     * @param int $transactionId The unique transaction identifier.
     * @param PaymentMethodSorting $sortBy Logic used to order the results.
     * @return PaymentMethodCollection List of available methods.
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
            usort(
                $methods,
                function (PaymentMethod $a, PaymentMethod $b) {
                    // Primary: merchant-configured display order
                    $orderComparison = $a->sortOrder <=> $b->sortOrder;
                    if ($orderComparison !== 0) {
                        return $orderComparison;
                    }

                    // Secondary tie-breaker: alphabetical by default title
                    return strcasecmp($a->title->getDefault(), $b->title->getDefault(), );
                },
            );
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
    public function getFailureMessage(
        int $spaceId,
        int $transactionId,
        string $shopLocale,
        string $defaultMessage,
    ): string {
        try {
            $transaction = $this->getTransaction(
                $spaceId,
                $transactionId,
            );
            if ($transaction->userFailureMessage !== null) {
                return $transaction->userFailureMessage->localize(
                    $shopLocale,
                ) ?? $defaultMessage;
            }
        } catch (\Throwable $e) {
            $this->logger->debug(
                "Failed to retrieve failure message for Transaction.",
                [
                    'transactionId' => $transactionId,
                    'spaceId' => $spaceId,
                    'error' => $e->getMessage(),
                ],
            );
        }
        return $defaultMessage;
    }

    /**
     * Retrieves a list of the most recent transactions.
     *
     * @param int $spaceId The identity space.
     * @param int $limit Maximum number of results to return.
     * @return TransactionCollection List of recent transactions.
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
     * Retrieves the URL where the customer should be redirected to complete payment.
     *
     * @param int $spaceId The identity space.
     * @param int $transactionId The unique transaction identifier.
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
     * Retrieves the latest state of a transaction from the gateway.
     *
     * @param int $spaceId The identity space.
     * @param int $transactionId The unique transaction identifier.
     * @return Transaction The domain object representing the transaction.
     */
    public function getTransaction(int $spaceId, int $transactionId): Transaction
    {
        $this->logger->debug("Fetching transaction.", [
            'transactionId' => $transactionId,
            'spaceId' => $spaceId,
        ]);
        return $this->gateway->get($spaceId, $transactionId);
    }

    /**
     * Searches for transactions matching the provided criteria.
     *
     * @param int $spaceId The identity space.
     * @param TransactionSearchCriteria $criteria Filters and pagination settings.
     * @return TransactionCollection List of matching transactions.
     */
    public function searchTransactions(int $spaceId, TransactionSearchCriteria $criteria): TransactionCollection
    {
        return $this->gateway->search($spaceId, $criteria);
    }

    /**
     * Updates an existing transaction with fresh context.
     *
     * @param TransactionContext $context The new context (must contain transactionId).
     * @return Transaction The updated transaction.
     * @throws \Throwable If the update is rejected by the gateway.
     */
    protected function updateTransaction(TransactionContext $context): Transaction
    {
        try {
            $this->logger->debug("Updating transaction.", [
                'transactionId' => $context->transactionId,
                'spaceId' => $context->spaceId,
            ]);

            $this->validateContext($context);

            $existing = $this->gateway->find($context->spaceId, $context->transactionId);
            if (!$existing) {
                $this->logger->error("Update failed: transaction not found.", [
                    'transactionId' => $context->transactionId,
                    'spaceId' => $context->spaceId,
                ]);
                throw new TransactionException(
                    "Transaction {$context->transactionId} in space {$context->spaceId} not found for update.",
                    new LocalizedString("Transaction not found for update."),
                );
            }

            $result = $this->gateway->update($existing->id, $existing->version, $context);

            $this->logger->debug("Transaction updated.", [
                'transactionId' => $result->id,
                'state' => $result->state->value,
                'spaceId' => $context->spaceId,
            ]);
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("Transaction update failed.", [
                'transactionId' => $context->transactionId,
                'spaceId' => $context->spaceId,
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Idempotently creates or updates a transaction session.
     *
     * This method attempts to update an existing session if valid (PENDING state).
     * If the session is expired, locked, or non-existent, it automatically falls back
     * to creating a fresh transaction to ensure the checkout flow is never blocked.
     *
     * @param TransactionContext $context The desired transaction state.
     * @param TransactionPersistenceInterface $persistenceStrategy Logic for saving the resulting ID.
     * @return Transaction The resulting active transaction.
     * @throws TransactionException If both update and fallback creation fail.
     */
    public function upsert(TransactionContext $context, TransactionPersistenceInterface $persistenceStrategy): Transaction
    {
        $result = null;

        if ($context->transactionId === null) {
            $result = $this->createTransaction($context);
        } else {
            try {
                // State Verification
                // We fetch the remote state to ensure initialization is only attempted on PENDING transactions.
                $existingTransaction = $this->gateway->find($context->spaceId, $context->transactionId);

                if (!$existingTransaction || $existingTransaction->state !== State::PENDING) {
                    // We throw to trigger the fallback logic below if the transaction is no longer mutable.
                    throw new TransactionException(
                        "Transaction {$context->transactionId} in space {$context->spaceId} not PENDING or not found.",
                        new LocalizedString("Transaction not PENDING or not found."),
                    );
                }

                if (isset($context->expectedGrandTotal) && $context->expectedGrandTotal < -0.00000001) {
                    $context->lineItems = $this->consistencyService->sanitizeNegativeLineItems($context->lineItems->all());
                    $context->expectedGrandTotal = 0.0;
                }

                // Atomic Update
                // We pass the version to the gateway to prevent concurrent overwrites (Optimistic Locking).
                $result = $this->gateway->update(
                    $existingTransaction->id,
                    $existingTransaction->version,
                    $context,
                );
            } catch (\Throwable $e) {
                // Fallback Recovery
                // If the update fails (e.g. version mismatch or state change), we start a new session.
                $this->logger->notice("Update failed; falling back to CREATE.", [
                    'transactionId' => $context->transactionId,
                    'spaceId' => $context->spaceId,
                    'exception' => $e,
                ]);
                $context->transactionId = null;
                $result = $this->createTransaction($context);
            }
        }

        // Persistence Management
        // If a new ID was generated during this process, we must inform the persistence layer.
        if ($result->id !== $context->transactionId) {
            try {
                $persistenceStrategy->persist($result->id);
                $this->logger->debug("Persisted new transaction via strategy.", [
                    'transactionId' => $result->id,
                ]);
            } catch (\Throwable $e) {
                // Persistence failure is critical as it breaks session continuity.
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
     * Validates that the transaction context satisfies basic business invariants.
     *
     * @param TransactionContext $context The context to validate.
     * @throws TransactionException If business rules are violated.
     */
    private function validateContext(TransactionContext $context): void
    {
        // Floating point epsilon for safe comparisons
        // Digital payments use decimals that can cause precision issues. We use an epsilon to avoid
        // false positives on zero comparisons.
        $epsilon = 0.00000001;

        // Negative Total Check
        // While discounts can be negative, the final transaction total must always be >= 0.
        if ($context->expectedGrandTotal < -$epsilon) {
            throw new TransactionTotalNegativeException();
        }
    }
}
