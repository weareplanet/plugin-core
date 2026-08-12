<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Invoice;

use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;

/**
 * Domain-facing service for reading transaction invoices.
 *
 * This is the entry point consumers use; it delegates to the configured
 * {@see InvoiceGatewayInterface}, which owns the API interaction, its logging and
 * its failure handling. The service deliberately adds no logging or exception
 * wrapping of its own: doing so would record every failure twice and re-wrap
 * exceptions that are already domain exceptions.
 */
#[LogContext(domain: 'invoice')]
class InvoiceService
{
    use DomainLoggerTrait;

    /**
     * @param InvoiceGatewayInterface $invoiceGateway The gateway to delegate to.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private InvoiceGatewayInterface $invoiceGateway,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * Finds a transaction invoice by ID.
     *
     * A missing invoice is an ordinary outcome, reported as null rather than as an
     * exception, so callers do not have to catch to handle the common path.
     *
     * @param int $spaceId The space ID.
     * @param int $invoiceId The invoice ID.
     * @return Invoice|null The invoice, or null if not found.
     */
    public function find(int $spaceId, int $invoiceId): ?Invoice
    {
        return $this->invoiceGateway->find($spaceId, $invoiceId);
    }

    /**
     * Gets a transaction invoice by ID and throws if it cannot be read.
     *
     * @param int $spaceId The space ID.
     * @param int $invoiceId The invoice ID.
     * @return Invoice The invoice.
     */
    public function get(int $spaceId, int $invoiceId): Invoice
    {
        return $this->invoiceGateway->get($spaceId, $invoiceId);
    }

    /**
     * Searches transaction invoices matching the given criteria.
     *
     * @param int $spaceId The space ID.
     * @param InvoiceSearchCriteria $criteria The search criteria.
     * @return InvoiceCollection The matching invoices.
     */
    public function search(int $spaceId, InvoiceSearchCriteria $criteria): InvoiceCollection
    {
        return $this->invoiceGateway->search($spaceId, $criteria);
    }
}
