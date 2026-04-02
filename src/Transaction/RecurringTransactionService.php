<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Token\TokenService;

/**
 * Service for handling recurring transactions.
 */
readonly class RecurringTransactionService
{
    public function __construct(
        private TransactionService $transactionService,
        private RecurringTransactionGatewayInterface $recurringGateway,
        private TokenService $tokenService,
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

        if (!$originalTransaction->token) {
            $this->logger->debug("Transaction $transactionId has no token. Attempting to create one.");
            $token = $this->tokenService->createTokenForTransaction($spaceId, $transactionId);
            if ($token) {
                $originalTransaction->token = $token;
            } else {
                $this->logger->error("Could not create token for Transaction $transactionId.");
            }
        }

        $context = TransactionContext::fromTransaction($originalTransaction);

        $newTransaction = $this->transactionService->createTransaction($context);

        return $this->recurringGateway->processRecurringPayment($spaceId, $newTransaction->id);
    }
}
