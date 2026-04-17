<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Transaction\RecurringTransactionGatewayInterface;
use WeArePlanet\PluginCore\Transaction\State as StateEnum;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\Sdk\Model\Charge as SdkCharge;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;

/**
 * Implementation of the RecurringTransactionGatewayInterface using the SDK V2.
 *
 * Uses `processWithToken` to charge the transaction against the token's stored
 * payment credentials (MIT — Merchant Initiated Transaction).
 */
class RecurringTransactionGateway implements RecurringTransactionGatewayInterface
{
    /**
     * @var SdkTransactionsService The SDK transaction service.
     */
    private SdkTransactionsService $transactionsService;

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
        $this->transactionsService = $this->sdkProvider->getService(SdkTransactionsService::class);
    }

    /**
     * Processes a recurring payment for an existing transaction.
     *
     * Charges the transaction using `processWithToken`, which leverages the
     * stored payment credentials from the linked token. Then fetches the
     * updated transaction to return it as the domain object.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return Transaction The processed transaction.
     * @throws \Exception If the processing fails.
     */
    public function processRecurringPayment(int $spaceId, int $transactionId): Transaction
    {
        $this->logger->debug("Processing recurring payment via token (ID: $transactionId).");

        try {
            // V2: processWithToken charges the transaction using the token's stored
            // payment credentials. Returns a Charge object, not a Transaction.
            $sdkCharge = $this->transactionsService->postPaymentTransactionsIdProcessWithToken(
                $transactionId,
                $spaceId,
            );

            $this->logger->debug("Charge completed for Transaction $transactionId.", [
                'chargeState' => (string) $sdkCharge->getState(),
            ]);

            // Fetch the updated transaction after the charge to return it
            $sdkTransaction = $this->transactionsService->getPaymentTransactionsId(
                $transactionId,
                $spaceId,
            );

            return $this->mapToTransaction($sdkTransaction);
        } catch (\Exception $e) {
            $this->logger->error("Failed to process recurring payment for Transaction $transactionId: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Maps an SDK Transaction to a domain Transaction.
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
}
