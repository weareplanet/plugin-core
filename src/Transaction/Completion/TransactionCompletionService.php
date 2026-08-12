<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Completion;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Transaction\Completion\Exception\CompletionException;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\Void\TransactionVoid;

/**
 * Service for handling transaction completions (Capture, Void).
 */
#[LogContext(domain: 'transaction', subdomain: 'completion')]
class TransactionCompletionService
{
    use DomainLoggerTrait;
    public function __construct(
        private readonly TransactionCompletionGatewayInterface $completionGateway,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * Captures an authorized transaction, in full or in part.
     *
     * Omit $request to capture the full remaining authorized amount. Pass one
     * carrying a {@see \WeArePlanet\PluginCore\LineItem\LineItemCollection}
     * to capture only specific line items — a shipment that fulfills part of an
     * order, for instance. A capture line item needs only `uniqueId`, `quantity`
     * and `amountIncludingTax` set; nothing else is transmitted.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID to capture.
     * @param CaptureRequest|null $request What to capture, or null for the full amount.
     * @return TransactionCompletion The resulting completion.
     * @throws TransactionException If the capture fails.
     */
    public function capture(
        int $spaceId,
        int $transactionId,
        ?CaptureRequest $request = null,
    ): TransactionCompletion {
        $isPartial = $request !== null && !$request->lineItems->isEmpty();

        try {
            $this->logger->debug("Capturing transaction.", [
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
                'partial' => $isPartial,
            ]);

            $result = $this->completionGateway->capture($spaceId, $transactionId, $request);

            $this->logger->debug("Transaction captured successfully.", [
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
                'completionId' => $result->id,
                'state' => $result->state->value,
                'partial' => $isPartial,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("Capture failed.", [
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
                'partial' => $isPartial,
                'exception' => $e,
            ]);
            throw new TransactionException(
                "Unable to capture transaction $transactionId in space $spaceId: " . $e->getMessage(),
                new LocalizedString("Unable to capture transaction."),
                $e,
            );
        }
    }

    /**
     * Whether the given transaction can be captured or voided right now.
     *
     * Check this before calling {@see capture()} or {@see void()}: a completion is
     * only accepted against an AUTHORIZED transaction, and accepting one moves the
     * transaction out of that state — so a transaction that has already been
     * captured reports false here rather than failing at the API.
     *
     * @param Transaction $transaction The transaction to test.
     * @return bool True when a capture or void can be attempted.
     */
    public function canComplete(Transaction $transaction): bool
    {
        return $transaction->state->allowsCompletion();
    }

    /**
     * Finds a completion by ID.
     *
     * A missing completion is an ordinary outcome, reported as null rather than as
     * an exception, so callers do not have to catch to handle the common path.
     * Chiefly used to verify an asynchronous completion webhook against the API.
     *
     * @param int $spaceId The space ID.
     * @param int $completionId The completion ID.
     * @return TransactionCompletion|null The completion, or null when it does not exist.
     * @throws CompletionException If the lookup itself fails.
     */
    public function find(int $spaceId, int $completionId): ?TransactionCompletion
    {
        return $this->completionGateway->find($spaceId, $completionId);
    }

    /**
     * Reads a completion by ID, throwing when it cannot be read.
     *
     * @param int $spaceId The space ID.
     * @param int $completionId The completion ID.
     * @return TransactionCompletion The completion.
     * @throws CompletionException If the completion cannot be read, including when
     *         no completion with that ID exists.
     */
    public function get(int $spaceId, int $completionId): TransactionCompletion
    {
        return $this->completionGateway->get($spaceId, $completionId);
    }

    /**
     * Voids an authorized transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID to void.
     * @return TransactionVoid The resulting void domain object.
     * @throws TransactionException If the void fails.
     */
    public function void(
        int $spaceId,
        int $transactionId,
    ): TransactionVoid {
        try {
            $this->logger->debug("Voiding transaction.", [
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
            ]);

            $void = $this->completionGateway->void(
                $spaceId,
                $transactionId,
            );

            $this->logger->debug("Transaction voided successfully.", [
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
                'state' => $void->state->value,
            ]);

            return $void;
        } catch (\Throwable $e) {
            $this->logger->error("Void failed.", [
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
                'exception' => $e,
            ]);
            throw new TransactionException(
                "Unable to void transaction $transactionId in space $spaceId: " . $e->getMessage(),
                new LocalizedString("Unable to void transaction."),
                $e,
            );
        }
    }
}
