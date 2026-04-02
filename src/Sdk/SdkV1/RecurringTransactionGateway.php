<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\SdkV1;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Transaction\RecurringTransactionGatewayInterface;
use WeArePlanet\PluginCore\Transaction\State as StateEnum;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;
use WeArePlanet\Sdk\Service\TransactionService as SdkTransactionService;

/**
 * Class RecurringTransactionGateway
 *
 * Implementation of the RecurringTransactionGatewayInterface using the SDK V1.
 */
class RecurringTransactionGateway implements RecurringTransactionGatewayInterface
{
    /**
     * @var SdkTransactionService The SDK transaction service.
     */
    private SdkTransactionService $transactionService;

    /**
     * RecurringTransactionGateway constructor.
     *
     * @param SdkProvider $sdkProvider The SDK provider.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        private readonly LoggerInterface $logger,
    ) {
        $this->transactionService = $this->sdkProvider->getService(SdkTransactionService::class);
    }

    /**
     * Maps an SDK Transaction to a domain Transaction.
     *
     * Duplicated from TransactionGateway to avoid coupling or refactoring.
     *
     * @param SdkTransaction $sdkTransaction The SDK transaction.
     * @return Transaction The domain transaction.
     */
    private function mapToTransaction(SdkTransaction $sdkTransaction): Transaction
    {
        $domain = new Transaction();
        $domain->id = $sdkTransaction->getId();
        $domain->spaceId = $sdkTransaction->getLinkedSpaceId();
        $domain->version = $sdkTransaction->getVersion();

        // Map State (String -> Enum)
        $domain->state = match ((string) $sdkTransaction->getState()) {
            'PENDING' => StateEnum::PENDING,
            'CONFIRMED' => StateEnum::CONFIRMED,
            'PROCESSING' => StateEnum::PROCESSING,
            'FAILED' => StateEnum::FAILED,
            'AUTHORIZED' => StateEnum::AUTHORIZED,
            'VOIDED' => StateEnum::VOIDED,
            'COMPLETED' => StateEnum::COMPLETED,
            'FULFILL' => StateEnum::FULFILL,
            'DECLINE' => StateEnum::DECLINE,
            default => StateEnum::PENDING,
        };

        return $domain;
    }

    /**
     * Processes a recurring payment for an existing transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return Transaction The processed transaction.
     * @throws \Exception If the processing fails.
     */
    public function processRecurringPayment(int $spaceId, int $transactionId): Transaction
    {
        $this->logger->debug("Processing recurring payment (ID: $transactionId).");

        try {
            $sdkTransaction = $this->transactionService->processWithoutUserInteraction($spaceId, $transactionId);
            $this->logger->debug("Recurring payment processed successfully for Transaction $transactionId.");

            return $this->mapToTransaction($sdkTransaction);
        } catch (\Exception $e) {
            $this->logger->error("Failed to process recurring payment for Transaction $transactionId: " . $e->getMessage());
            throw $e;
        }
    }
}
