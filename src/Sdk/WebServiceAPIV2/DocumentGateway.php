<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Document\DocumentGatewayInterface;
use WeArePlanet\PluginCore\Document\RenderedDocument;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Document\Exception\DocumentException;
use WeArePlanet\Sdk\Model\RenderedDocument as SdkRenderedDocument;
use WeArePlanet\Sdk\Service\RefundsService as SdkRefundsService;
use WeArePlanet\Sdk\Service\TransactionCompletionsService as SdkTransactionCompletionsService;
use WeArePlanet\Sdk\Service\TransactionInvoicesService as SdkTransactionInvoicesService;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;

/**
 * Gateway for retrieving documents using the SDK.
 */
#[LogContext(domain: 'documentation')]
class DocumentGateway implements DocumentGatewayInterface
{
    use DomainLoggerTrait;
    private SdkTransactionInvoicesService $transactionInvoicesService;
    private SdkTransactionsService $transactionsService;
    private SdkRefundsService $refundsService;
    private SdkTransactionCompletionsService $transactionCompletionsService;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
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
                throw new DocumentException(
                    "No completion found for transaction $transactionId in space $spaceId when fetching invoice.",
                    new LocalizedString('No invoice found for the transaction.'),
                );
            }

            if (is_object($completions) && method_exists($completions, 'getData')) {
                $completionData = $completions->getData();
            } else {
                $completionData = (array)$completions;
            }

            if (empty($completionData) || count($completionData) === 0) {
                throw new DocumentException(
                    "No completion data retrieved for transaction $transactionId in space $spaceId when fetching invoice.",
                    new LocalizedString('No completion found for the transaction.'),
                );
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
                throw new DocumentException(
                    "No invoice found linked to completion $completionId for transaction $transactionId in space $spaceId.",
                    new LocalizedString('No invoice found linked to the transaction completion.'),
                );
            }

            $invoice = $invoices[0];
            $sdkDocument = $this->transactionInvoicesService->getPaymentTransactionsInvoicesIdDocument($invoice->getId(), $spaceId);

            return $this->mapSdkDocument($sdkDocument);
        } catch (DocumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error("DocumentGateway: Failed to get invoice.", ['exception' => $e]);
            throw SdkProvider::wrapException(
                $e,
                DocumentException::class,
                'getPaymentTransactionsInvoicesIdDocument',
                ['spaceId' => $spaceId, 'transactionId' => $transactionId],
                'The invoice document could not be retrieved.',
            );
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
        } catch (\Throwable $e) {
            $this->logger->error("DocumentGateway: Failed to get packing slip.", ['exception' => $e]);
            throw SdkProvider::wrapException(
                $e,
                DocumentException::class,
                'getPaymentTransactionsIdPackingSlipDocument',
                ['spaceId' => $spaceId, 'transactionId' => $transactionId],
                'The packing slip could not be retrieved.',
            );
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
        } catch (\Throwable $e) {
            $this->logger->error("DocumentGateway: Failed to get refund credit note.", ['exception' => $e]);
            throw SdkProvider::wrapException(
                $e,
                DocumentException::class,
                'getPaymentRefundsIdDocument',
                ['spaceId' => $spaceId, 'refundId' => $refundId],
                'The credit note could not be retrieved.',
            );
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
