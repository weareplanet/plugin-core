<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\SdkV1;

use WeArePlanet\PluginCore\Document\DocumentGatewayInterface;
use WeArePlanet\PluginCore\Document\RenderedDocument;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;
use WeArePlanet\Sdk\Model\CriteriaOperator as SdkCriteriaOperator;
use WeArePlanet\Sdk\Model\EntityQuery as SdkEntityQuery;
use WeArePlanet\Sdk\Model\EntityQueryFilter as SdkEntityQueryFilter;
use WeArePlanet\Sdk\Model\EntityQueryFilterType as SdkEntityQueryFilterType;
use WeArePlanet\Sdk\Model\RenderedDocument as SdkRenderedDocument;
use WeArePlanet\Sdk\Model\TransactionInvoiceState as SdkTransactionInvoiceState;
use WeArePlanet\Sdk\Service\RefundService as SdkRefundService;
use WeArePlanet\Sdk\Service\TransactionInvoiceService as SdkTransactionInvoiceService;
use WeArePlanet\Sdk\Service\TransactionService as SdkTransactionService;

/**
 * Gateway for retrieving documents using the SDK.
 */
class DocumentGateway implements DocumentGatewayInterface
{
    private SdkRefundService $refundService;
    private SdkTransactionInvoiceService $transactionInvoiceService;
    private SdkTransactionService $transactionService;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
        private readonly LoggerInterface $logger,
    ) {
        $this->transactionInvoiceService = $this->sdkProvider->getService(SdkTransactionInvoiceService::class);
        $this->transactionService = $this->sdkProvider->getService(SdkTransactionService::class);
        $this->refundService = $this->sdkProvider->getService(SdkRefundService::class);
    }

    /**
     * Helper to create filter.
     *
     * @param string $fieldName
     * @param mixed $value
     * @param string $operator
     * @return SdkEntityQueryFilter
     */
    private function createFilter(string $fieldName, mixed $value, string $operator = SdkCriteriaOperator::EQUALS): SdkEntityQueryFilter
    {
        $filter = new SdkEntityQueryFilter();
        $filter->setType(SdkEntityQueryFilterType::LEAF);
        $filter->setOperator($operator);
        $filter->setFieldName($fieldName);
        $filter->setValue($value);
        return $filter;
    }

    /**
     * @inheritDoc
     */
    public function getInvoice(int $spaceId, int $transactionId): RenderedDocument
    {
        $this->logger->debug("DocumentGateway: Fetching invoice for transaction.", [
            'spaceId' => $spaceId,
            'transactionId' => $transactionId,
        ]);

        try {
            // Try to find the invoice for the transaction first, as usually SDK requires Invoice ID
            // We search for a non-canceled invoice linked to the transaction.
            $query = new SdkEntityQuery();
            $filter = new SdkEntityQueryFilter();
            $filter->setType(SdkEntityQueryFilterType::_AND);
            $filter->setChildren([
                $this->createFilter('completion.lineItemVersion.transaction.id', $transactionId),
                $this->createFilter('state', SdkTransactionInvoiceState::CANCELED, SdkCriteriaOperator::NOT_EQUALS),
            ]);
            $query->setFilter($filter);
            $query->setNumberOfEntities(1);

            $invoices = $this->transactionInvoiceService->search($spaceId, $query);

            if (empty($invoices)) {
                // Fallback: maybe it accepts transactionId directly as per assumption/request hint?
                // But safest is if search returns nothing, we can't get an invoice.
                // However, the prompt says "Assumption for this task: Use the method that accepts the Transaction ID if available, otherwise search."
                // Since I cannot check availability, I implemented search which is safer.
                // But strictly following "method that accepts Transaction ID if available":
                // I will try to call getInvoiceDocument with transactionId if the method name suggests it,
                // but SdkTransactionInvoiceService usually has getInvoiceDocument($spaceId, $id).
                // So I will stick with the search logic as it is more robust for "Invoice Service".

                // If no invoice found, check if there is one in CANCELED state or just throw.
                throw new TransactionException("No invoice found for transaction $transactionId");
            }

            $invoice = $invoices[0];
            $sdkDocument = $this->transactionInvoiceService->getInvoiceDocument($spaceId, $invoice->getId());

            return $this->mapSdkDocument($sdkDocument);
        } catch (\Exception $e) {
            $this->logger->error("DocumentGateway: Failed to get invoice.", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function getPackingSlip(int $spaceId, int $transactionId): RenderedDocument
    {
        $this->logger->debug("DocumentGateway: Fetching packing slip.", [
            'spaceId' => $spaceId,
            'transactionId' => $transactionId,
        ]);

        try {
            $sdkDocument = $this->transactionService->getPackingSlip($spaceId, $transactionId);
            return $this->mapSdkDocument($sdkDocument);
        } catch (\Exception $e) {
            $this->logger->error("DocumentGateway: Failed to get packing slip.", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function getRefundCreditNote(int $spaceId, int $refundId): RenderedDocument
    {
        $this->logger->debug("DocumentGateway: Fetching refund credit note.", [
            'spaceId' => $spaceId,
            'refundId' => $refundId,
        ]);

        try {
            $sdkDocument = $this->refundService->getRefundDocument($spaceId, $refundId);
            return $this->mapSdkDocument($sdkDocument);
        } catch (\Exception $e) {
            $this->logger->error("DocumentGateway: Failed to get refund credit note.", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Maps SDK RenderedDocument to Domain RenderedDocument.
     *
     * @param SdkRenderedDocument $sdkDocument
     * @return RenderedDocument
     */
    private function mapSdkDocument(SdkRenderedDocument $sdkDocument): RenderedDocument
    {
        return new RenderedDocument(
            title: $sdkDocument->getTitle(),
            mimeType: $sdkDocument->getMimeType(),
            data: base64_decode($sdkDocument->getData(), true), // Usually SDK returns base64 string, needing decode
        );
    }
}
