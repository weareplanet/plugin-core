<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\Log\LoggerInterface;

/**
 * Service for handling recurring transactions.
 */
readonly class RecurringTransactionService
{
    public function __construct(
        private TransactionService $transactionService,
        private RecurringTransactionGatewayInterface $recurringGateway,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Processes a recurring payment for an existing transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return Transaction The processed transaction.
     * @throws \Throwable If processing fails.
     */
    public function processRecurringPayment(int $spaceId, int $transactionId): Transaction
    {
        $this->logger->debug("Processing recurring payment for Transaction $transactionId in Space $spaceId.");

        $originalTransaction = $this->transactionService->getTransaction($spaceId, $transactionId);

        // A token with stored payment credentials is required for recurring charges.
        // The original transaction must have been created with tokenizationMode = FORCE_CREATION
        // so the API automatically generates a token when the payment completes.
        if ($originalTransaction->token === null) {
            throw new \RuntimeException(
                "Transaction $transactionId has no token. "
                . "The original transaction must be created with tokenizationMode = FORCE_CREATION "
                . "to enable recurring payments.",
            );
        }

        // The billing address is required on the transaction context to calculate taxes and process billing correctly.
        if ($originalTransaction->billingAddress === null) {
            throw new \RuntimeException("Transaction $transactionId has no billing address.");
        }

        $context = TransactionContext::fromTransaction($originalTransaction);

        $newTransaction = $this->transactionService->createTransaction($context);

        return $this->recurringGateway->processRecurringPayment($spaceId, $newTransaction->id);
    }
}
