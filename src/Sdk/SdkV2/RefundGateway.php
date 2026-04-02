<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\SdkV2;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Refund\Refund;
use WeArePlanet\PluginCore\Refund\RefundContext;
use WeArePlanet\PluginCore\Refund\RefundGatewayInterface;
use WeArePlanet\PluginCore\Refund\State as StateEnum;
use WeArePlanet\PluginCore\Refund\Exception\InvalidRefundException;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\Sdk\Model\LineItemReductionCreate as SdkLineItemReductionCreate;
use WeArePlanet\Sdk\Model\RefundCreate as SdkRefundCreate;
use WeArePlanet\Sdk\Model\RefundType as SdkRefundType;
use WeArePlanet\Sdk\Service\RefundsService as SdkRefundService;
use WeArePlanet\Sdk\Model\Refund as SdkRefund;

class RefundGateway implements RefundGatewayInterface
{
    private SdkRefundService $sdkRefundService;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
        private readonly LoggerInterface $logger,
    ) {
        $this->sdkRefundService = $this->sdkProvider->getService(SdkRefundService::class);
    }

    /**
     * @return Refund[]
     */
    public function findByTransaction(int $spaceId, int $transactionId): array
    {
        // V2 Search: using query string 'transaction.id:<id>'
        // SEARCH for refunds linked to the transaction using the 'query' filter.
        $query = "transaction.id:$transactionId";

        try {
            $sdkRefunds = $this->sdkRefundService->getPaymentRefundsSearch($spaceId, null, null, null, null, $query);
            $refunds = [];
            // Handle ListResponse or Array
            $items = (is_object($sdkRefunds) && method_exists($sdkRefunds, 'getData')) ? $sdkRefunds->getData() : (array)$sdkRefunds;
            foreach ($items as $sdkRefund) {
                $refunds[] = $this->mapToRefund($sdkRefund, $transactionId);
            }
            return $refunds;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to find refunds for Transaction $transactionId: {$e->getMessage()}");
            return [];
        }
    }

    public function refund(int $spaceId, RefundContext $context): Refund
    {
        $this->logger->debug("Preparing refund for Transaction {$context->transactionId}", [
            'amount' => $context->amount,
            'spaceId' => $spaceId,
        ]);

        try {
            $sdkRefundCreate = new SdkRefundCreate();
            $sdkRefundCreate->setTransaction($context->transactionId);
            $sdkRefundCreate->setAmount($context->amount);
            $sdkRefundCreate->setMerchantReference($context->merchantReference);
            $sdkRefundCreate->setExternalId(uniqid((string)$context->transactionId . '-', true));
            $sdkRefundCreate->setType(match ($context->type->value) {
                'MERCHANT_INITIATED_ONLINE' => SdkRefundType::MERCHANT_INITIATED_ONLINE,
                'MERCHANT_INITIATED_OFFLINE' => SdkRefundType::MERCHANT_INITIATED_OFFLINE,
                'CUSTOMER_INITIATED_AUTOMATIC' => SdkRefundType::CUSTOMER_INITIATED_AUTOMATIC,
                'CUSTOMER_INITIATED_MANUAL' => SdkRefundType::CUSTOMER_INITIATED_MANUAL,
                default => SdkRefundType::MERCHANT_INITIATED_ONLINE,
            });

            if (!empty($context->lineItems)) {
                $sdkReductions = [];
                foreach ($context->lineItems as $item) {
                    $uniqueId = $item['uniqueId'];
                    $qty = $item['quantity'];
                    $amt = $item['amount'];

                    $this->logger->debug("Adding Reduction: ID=$uniqueId, Qty=$qty, Amt=$amt");

                    $sdkReduction = new SdkLineItemReductionCreate();
                    $sdkReduction->setLineItemUniqueId($uniqueId);
                    $sdkReduction->setQuantityReduction($qty);
                    $sdkReduction->setUnitPriceReduction($amt);
                    $sdkReductions[] = $sdkReduction;
                }
                $sdkRefundCreate->setReductions($sdkReductions);
            }

            $this->logger->debug("Refund Create Payload", [
                'amount' => $sdkRefundCreate->getAmount(),
                'reductions_count' => count($sdkRefundCreate->getReductions() ?? []),
            ]);

            $sdkRefund = $this->sdkRefundService->postPaymentRefunds($spaceId, $sdkRefundCreate);

            $this->logger->debug("Refund created successfully. ID: {$sdkRefund->getId()}, State: {$sdkRefund->getState()}");

            return $this->mapToRefund($sdkRefund, $context->transactionId);
        } catch (\Throwable $e) {
            $this->logger->error("Refund failed for Transaction {$context->transactionId}: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString(),
            ]);
            throw new InvalidRefundException("Unable to process refund: {$e->getMessage()}", 0, $e);
        }
    }

    private function mapToRefund(SdkRefund $sdkRefund, int $transactionId): Refund
    {
        $refund = new Refund();
        $refund->id = (int)$sdkRefund->getId();
        $refund->amount = (float)$sdkRefund->getAmount();
        $refund->transactionId = $transactionId;
        $refund->externalId = $sdkRefund->getExternalId();

        $refund->state = match ((string)$sdkRefund->getState()) {
            'CREATE' => StateEnum::CREATE,
            'SCHEDULED' => StateEnum::SCHEDULED,
            'PENDING' => StateEnum::PENDING,
            'MANUAL_CHECK' => StateEnum::MANUAL_CHECK,
            'FAILED' => StateEnum::FAILED,
            'SUCCESSFUL' => StateEnum::SUCCESSFUL,
            default => StateEnum::PENDING, // Safe fallback
        };

        return $refund;
    }
}
