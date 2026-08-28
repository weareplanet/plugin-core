<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV1;

use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Refund\Exception\RefundException;
use WeArePlanet\PluginCore\Refund\Refund;
use WeArePlanet\PluginCore\Refund\RefundCollection;
use WeArePlanet\PluginCore\Refund\RefundContext;
use WeArePlanet\PluginCore\Refund\RefundGatewayInterface;
use WeArePlanet\PluginCore\Refund\State as StateEnum;
use WeArePlanet\PluginCore\Sdk\DateTimeMapperTrait;
use WeArePlanet\PluginCore\Sdk\FailureReasonMapperTrait;
use WeArePlanet\PluginCore\Sdk\LineItemMapperTrait;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\Sdk\Model\CriteriaOperator as SdkCriteriaOperator;
use WeArePlanet\Sdk\Model\EntityQuery as SdkEntityQuery;
use WeArePlanet\Sdk\Model\EntityQueryFilter as SdkEntityQueryFilter;
use WeArePlanet\Sdk\Model\EntityQueryFilterType as SdkEntityQueryFilterType;
use WeArePlanet\Sdk\Model\LineItemReductionCreate as SdkLineItemReductionCreate;
use WeArePlanet\Sdk\Model\Refund as SdkRefund;
use WeArePlanet\Sdk\Model\RefundCreate as SdkRefundCreate;
use WeArePlanet\Sdk\Model\RefundType as SdkRefundType;
use WeArePlanet\Sdk\Service\RefundService as SdkRefundService;

#[LogContext(domain: 'refund')]
class RefundGateway implements RefundGatewayInterface
{
    use DateTimeMapperTrait;
    use DomainLoggerTrait;
    use FailureReasonMapperTrait;
    use LineItemMapperTrait;

    private SdkRefundService $sdkRefundService;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
        $this->sdkRefundService = $this->sdkProvider->getService(SdkRefundService::class);
    }

    public function findById(int $spaceId, int $refundId): Refund
    {
        $this->logger->debug("Reading refund.", [
            'refundId' => $refundId,
            'spaceId' => $spaceId,
        ]);

        try {
            $sdkRefund = $this->sdkRefundService->read($spaceId, $refundId);
            return $this->mapToRefund($sdkRefund, (int)$sdkRefund->getTransaction()->getId());
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to read refund.',
                [
                    'errorMessage' => $e->getMessage(),
                    'exception' => $e,
                    'refundId' => $refundId,
                    'spaceId' => $spaceId,
                ],
            );
            throw SdkProvider::wrapException(
                $e,
                RefundException::class,
                'read',
                ['spaceId' => $spaceId, 'refundId' => $refundId],
                'An error occurred while retrieving the refund.',
            );
        }
    }

    public function findByTransaction(int $spaceId, int $transactionId): RefundCollection
    {
        $query = new SdkEntityQuery();
        $filter = new SdkEntityQueryFilter();
        $filter->setType(SdkEntityQueryFilterType::LEAF);
        $filter->setOperator(SdkCriteriaOperator::EQUALS);
        $filter->setFieldName('transaction.id');
        $filter->setValue($transactionId);
        $query->setFilter($filter);

        try {
            $sdkRefunds = $this->sdkRefundService->search($spaceId, $query);
            $refunds = [];
            foreach ($sdkRefunds as $sdkRefund) {
                $refunds[] = $this->mapToRefund($sdkRefund, $transactionId);
            }
            return new RefundCollection(...$refunds);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to find refunds for transaction.',
                [
                    'errorMessage' => $e->getMessage(),
                    'exception' => $e,
                    'spaceId' => $spaceId,
                    'transactionId' => $transactionId,
                ],
            );
            throw SdkProvider::wrapException(
                $e,
                RefundException::class,
                'search',
                ['spaceId' => $spaceId, 'transactionId' => $transactionId],
                'An error occurred while retrieving refunds.',
            );
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

        $reason = $sdkRefund->getFailureReason();
        if ($reason !== null) {
            $refund->failureReason = $this->mapSdkFailureReason($reason);
        }

        $refund->createdOn = $this->toDateTimeImmutable($sdkRefund->getCreatedOn());
        $refund->failedOn = $this->toDateTimeImmutable($sdkRefund->getFailedOn());

        // The SDK's line items carry the reductions applied by this refund.
        $sdkLineItems = $sdkRefund->getLineItems();
        if (!empty($sdkLineItems)) {
            $refund->lineItems = new LineItemCollection(...array_map([$this, 'mapToLineItem'], $sdkLineItems));
        }

        // The reduced line items carry the post-refund cart state.
        $sdkReducedLineItems = $sdkRefund->getReducedLineItems();
        if (!empty($sdkReducedLineItems)) {
            $refund->reducedLineItems = new LineItemCollection(...array_map([$this, 'mapToLineItem'], $sdkReducedLineItems));
        }

        return $refund;
    }

    public function refund(int $spaceId, RefundContext $context): Refund
    {
        $this->logger->debug("Preparing refund.", [
            'transactionId' => $context->transactionId,
            'amount' => $context->amount,
            'spaceId' => $spaceId,
        ]);

        try {
            $sdkRefundCreate = new SdkRefundCreate();
            $sdkRefundCreate->setTransaction($context->transactionId);
            $sdkRefundCreate->setAmount($context->amount);
            $sdkRefundCreate->setMerchantReference($context->merchantReference);
            // Use the caller-supplied idempotency key when given, so a retried
            // refund (e.g. after a timeout) is recognised by the API as the
            // same refund instead of creating a duplicate. Fall back to a
            // generated ID only when the caller didn't provide one.
            $sdkRefundCreate->setExternalId($context->externalId ?? uniqid((string)$context->transactionId . '-', true));
            $sdkRefundCreate->setType(match ($context->type->value) {
                'MERCHANT_INITIATED_ONLINE' => SdkRefundType::MERCHANT_INITIATED_ONLINE,
                'MERCHANT_INITIATED_OFFLINE' => SdkRefundType::MERCHANT_INITIATED_OFFLINE,
                'CUSTOMER_INITIATED_AUTOMATIC' => SdkRefundType::CUSTOMER_INITIATED_AUTOMATIC,
                'CUSTOMER_INITIATED_MANUAL' => SdkRefundType::CUSTOMER_INITIATED_MANUAL,
                default => SdkRefundType::MERCHANT_INITIATED_ONLINE,
            });

            if (!$context->lineItems->isEmpty()) {
                $sdkReductions = [];
                foreach ($context->lineItems as $item) {
                    $uniqueId = $item->uniqueId;
                    $qty = $item->returnedQuantity;
                    $amt = $item->unitPriceReduction;

                    $this->logger->debug("Adding refund line item reduction.", [
                        'uniqueId' => $uniqueId,
                        'quantity' => $qty,
                        'amount' => $amt,
                    ]);

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

            $sdkRefund = $this->sdkRefundService->refund($spaceId, $sdkRefundCreate);

            $this->logger->debug("Refund created successfully.", [
                'refundId' => $sdkRefund->getId(),
                'state' => $sdkRefund->getState(),
                'transactionId' => $context->transactionId,
                'spaceId' => $spaceId,
            ]);

            return $this->mapToRefund($sdkRefund, $context->transactionId);
        } catch (\Throwable $e) {
            $this->logger->error("Refund failed.", [
                'transactionId' => $context->transactionId,
                'spaceId' => $spaceId,
                'exception' => $e,
            ]);
            $exception = SdkProvider::wrapException(
                $e,
                RefundException::class,
                'refund',
                ['spaceId' => $spaceId, 'transactionId' => $context->transactionId],
                'An error occurred while processing the refund.',
            );


            throw $exception;
        }
    }

}
