<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Completion;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;

/**
 * Service for handling transaction completions (Capture, Void).
 */
readonly class TransactionCompletionService
{
    public function __construct(
        private TransactionCompletionGatewayInterface $completionGateway,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Captures an authorized transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID to capture.
     * @return TransactionCompletion The resulting completion.
     * @throws TransactionException If the capture fails.
     */
    public function capture(int $spaceId, int $transactionId): TransactionCompletion
    {
        try {
            $this->logger->debug("Capturing transaction $transactionId in Space $spaceId.");

            $result = $this->completionGateway->capture($spaceId, $transactionId);

            $this->logger->debug("Transaction $transactionId captured successfully. Completion ID: {$result->id}, State: {$result->state->value}");

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("Capture failed for Transaction $transactionId: " . $e->getMessage());
            throw new TransactionException("Unable to capture transaction: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Voids an authorized transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID to void.
     * @return string The state of the void operation.
     * @throws TransactionException If the void fails.
     */
    public function void(int $spaceId, int $transactionId): string
    {
        try {
            $this->logger->debug("Voiding transaction $transactionId in Space $spaceId.");

            $state = $this->completionGateway->void($spaceId, $transactionId);

            $this->logger->debug("Transaction $transactionId voided successfully. State: $state");

            return $state;
        } catch (\Throwable $e) {
            $this->logger->error("Void failed for Transaction $transactionId: " . $e->getMessage());
            throw new TransactionException("Unable to void transaction: " . $e->getMessage(), 0, $e);
        }
    }
}
