<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Invoice;

/**
 * Read-only gateway for transaction invoices.
 */
interface InvoiceGatewayInterface
{
    /**
     * Finds a transaction invoice by ID.
     *
     * @param int $spaceId The space ID.
     * @param int $invoiceId The invoice ID.
     * @return Invoice|null The invoice, or null if not found.
     */
    public function find(int $spaceId, int $invoiceId): ?Invoice;

    /**
     * Gets a transaction invoice by ID and throws if it cannot be read.
     *
     * @param int $spaceId The space ID.
     * @param int $invoiceId The invoice ID.
     * @return Invoice The invoice.
     */
    public function get(int $spaceId, int $invoiceId): Invoice;

    /**
     * Searches transaction invoices matching the given criteria.
     *
     * @param int $spaceId The space ID.
     * @param InvoiceSearchCriteria $criteria The search criteria.
     * @return InvoiceCollection The matching invoices.
     */
    public function search(int $spaceId, InvoiceSearchCriteria $criteria): InvoiceCollection;
}
