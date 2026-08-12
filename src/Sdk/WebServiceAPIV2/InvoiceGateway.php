<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\InvoiceMapperTrait;
use WeArePlanet\PluginCore\Transaction\Invoice\Exception\InvoiceException;
use WeArePlanet\PluginCore\Transaction\Invoice\Invoice;
use WeArePlanet\PluginCore\Transaction\Invoice\InvoiceCollection;
use WeArePlanet\PluginCore\Transaction\Invoice\InvoiceGatewayInterface;
use WeArePlanet\PluginCore\Transaction\Invoice\InvoiceSearchCriteria;
use WeArePlanet\Sdk\ApiException;
use WeArePlanet\Sdk\Service\TransactionInvoicesService as SdkTransactionInvoicesService;

#[LogContext(domain: 'transaction', subdomain: 'invoice')]
class InvoiceGateway implements InvoiceGatewayInterface
{
    use DomainLoggerTrait;
    use InvoiceMapperTrait;

    private SdkTransactionInvoicesService $transactionInvoicesService;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
        $this->transactionInvoicesService = $this->sdkProvider->getService(SdkTransactionInvoicesService::class);
    }

    public function find(int $spaceId, int $invoiceId): ?Invoice
    {
        $this->logger->debug('Gateway: Finding transaction invoice.', [
            'invoiceId' => $invoiceId,
            'spaceId' => $spaceId,
        ]);

        try {
            $sdkInvoice = $this->transactionInvoicesService->getPaymentTransactionsInvoicesId($invoiceId, $spaceId);
            return $this->mapToInvoice($sdkInvoice);
        } catch (\Throwable $e) {
            if ($e instanceof ApiException && $e->getCode() === 404) {
                $this->logger->debug('Gateway: Transaction invoice not found.', [
                    'invoiceId' => $invoiceId,
                    'spaceId' => $spaceId,
                ]);
                return null;
            }

            $this->logger->error('Gateway: Failed to find transaction invoice.', [
                'exception' => $e,
                'invoiceId' => $invoiceId,
                'spaceId' => $spaceId,
            ]);
            throw SdkProvider::wrapException(
                $e,
                InvoiceException::class,
                'read',
                ['spaceId' => $spaceId, 'invoiceId' => $invoiceId],
                'An error occurred while retrieving the transaction invoice.',
            );
        }
    }

    public function get(int $spaceId, int $invoiceId): Invoice
    {
        $this->logger->debug('Gateway: Reading transaction invoice.', [
            'invoiceId' => $invoiceId,
            'spaceId' => $spaceId,
        ]);

        try {
            $sdkInvoice = $this->transactionInvoicesService->getPaymentTransactionsInvoicesId($invoiceId, $spaceId);
            return $this->mapToInvoice($sdkInvoice);
        } catch (\Throwable $e) {
            $this->logger->error('Gateway: Failed to read transaction invoice.', [
                'exception' => $e,
                'invoiceId' => $invoiceId,
                'spaceId' => $spaceId,
            ]);
            throw SdkProvider::wrapException(
                $e,
                InvoiceException::class,
                'read',
                ['spaceId' => $spaceId, 'invoiceId' => $invoiceId],
                'An error occurred while retrieving the transaction invoice.',
            );
        }
    }

    public function search(int $spaceId, InvoiceSearchCriteria $criteria): InvoiceCollection
    {
        $this->logger->debug('Gateway: Searching transaction invoices.', ['spaceId' => $spaceId]);

        // V2 Search: build the 'field:value' query string.
        $queryParts = [];
        foreach ($criteria->filters as $field => $value) {
            $queryParts[] = "$field:$value";
        }
        $queryString = implode(' ', $queryParts);

        // The V2 'order' parameter follows the 'field:DIRECTION' format.
        $order = null;
        if ($criteria->sortField !== null) {
            $order = $criteria->sortField . ':' . ($criteria->sortOrder ?? 'DESC');
        }

        try {
            $results = $this->transactionInvoicesService->getPaymentTransactionsInvoicesSearch($spaceId, null, $criteria->limit, null, $order, $queryString);
            $items = (is_object($results) && method_exists($results, 'getData')) ? $results->getData() : (array)$results;
            return new InvoiceCollection(...array_map([$this, 'mapToInvoice'], $items));
        } catch (\Throwable $e) {
            $this->logger->error('Gateway: Failed to search transaction invoices.', [
                'exception' => $e,
                'spaceId' => $spaceId,
            ]);
            throw SdkProvider::wrapException(
                $e,
                InvoiceException::class,
                'search',
                ['spaceId' => $spaceId],
                'An error occurred while searching transaction invoices.',
            );
        }
    }
}
