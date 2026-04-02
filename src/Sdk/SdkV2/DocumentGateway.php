<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\SdkV2;

use WeArePlanet\PluginCore\Document\DocumentGatewayInterface;
use WeArePlanet\PluginCore\Document\RenderedDocument;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\Sdk\Model\RenderedDocument as SdkRenderedDocument;
use WeArePlanet\Sdk\Service\RefundsService as SdkRefundsService;
use WeArePlanet\Sdk\Service\TransactionInvoicesService as SdkTransactionInvoicesService;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;
use WeArePlanet\Sdk\Service\TransactionCompletionsService as SdkTransactionCompletionsService;

/**
 * Gateway for retrieving documents using the SDK.
 */
class DocumentGateway implements DocumentGatewayInterface
{
    private SdkTransactionInvoicesService $transactionInvoicesService;
    private SdkTransactionsService $transactionsService;
    private SdkRefundsService $refundsService;
    private SdkTransactionCompletionsService $transactionCompletionsService;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
        private readonly LoggerInterface $logger,
    ) {
        $this->transactionInvoicesService = $this->sdkProvider->getService(SdkTransactionInvoicesService::class);
        $this->transactionsService = $this->sdkProvider->getService(SdkTransactionsService::class);
        $this->refundsService = $this->sdkProvider->getService(SdkRefundsService::class);
        $this->transactionCompletionsService = $this->sdkProvider->getService(SdkTransactionCompletionsService::class);
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
            // Find the Invoice by searching for the corresponding Completion.
            // Invoices are linked to Completions rather than directly to the Transaction.

            // Find the Completion related to the transaction.
            // Search for the Completion using the Transaction ID via the deep path.
            $completionQuery = "lineItemVersion.transaction.id:$transactionId";
            $completions = $this->transactionCompletionsService->getPaymentTransactionsCompletionsSearch($spaceId, null, 1, null, null, $completionQuery);

            if (empty($completions)) {
                // Ensure a completion exists, as invoices are typically generated upon completion.
                throw new \Exception("No invoice found for transaction $transactionId");
            }

            if (is_object($completions) && method_exists($completions, 'getData')) {
                $completionData = $completions->getData();
            } else {
                $completionData = (array)$completions;
            }

            if (empty($completionData) || count($completionData) === 0) {
                throw new \Exception("No completion found for transaction $transactionId.");
            }

            $completionId = $completionData[0]->getId();

            // Find the Invoice linked to the identified Completion.
            $invoiceQuery = "completion:$completionId";
            $invoicesResponse = $this->transactionInvoicesService->getPaymentTransactionsInvoicesSearch($spaceId, null, 1, null, null, $invoiceQuery);

            if (is_object($invoicesResponse) && method_exists($invoicesResponse, 'getData')) {
                $invoices = $invoicesResponse->getData();
            } else {
                $invoices = (array)$invoicesResponse;
            }

            if (empty($invoices) || count($invoices) === 0) {
                throw new \Exception("No invoice found linked to completion $completionId (Transaction: $transactionId)");
            }

            $invoice = $invoices[0];
            $sdkDocument = $this->transactionInvoicesService->getPaymentTransactionsInvoicesIdDocument($invoice->getId(), $spaceId);

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
            $sdkDocument = $this->transactionsService->getPaymentTransactionsIdPackingSlipDocument($transactionId, $spaceId);
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
            $sdkDocument = $this->refundsService->getPaymentRefundsIdDocument($refundId, $spaceId);
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
            data: base64_decode($sdkDocument->getData(), true),
        );
    }
}
