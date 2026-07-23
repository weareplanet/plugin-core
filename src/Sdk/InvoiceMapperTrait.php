<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\Transaction\Invoice\State as StateEnum;
use WeArePlanet\PluginCore\Transaction\Invoice\Invoice;
use WeArePlanet\Sdk\Model\TransactionInvoice as SdkTransactionInvoice;

/**
 * Shared SDK Invoice -> domain mapping.
 */
trait InvoiceMapperTrait
{
    use DateTimeMapperTrait;
    use LineItemMapperTrait;

    /**
     * Maps an SDK Invoice to a domain Invoice.
     *
     * @param SdkTransactionInvoice $sdkInvoice
     * @return Invoice
     */
    protected function mapToInvoice(SdkTransactionInvoice $sdkInvoice): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = (int)$sdkInvoice->getId();
        $invoice->linkedSpaceId = (int)$sdkInvoice->getLinkedSpaceId();
        $invoice->linkedTransactionId = $sdkInvoice->getLinkedTransaction() !== null
            ? (int)$sdkInvoice->getLinkedTransaction()
            : null;

        $invoice->state = match ((string)$sdkInvoice->getState()) {
            'CREATE' => StateEnum::CREATE,
            'OPEN' => StateEnum::OPEN,
            'OVERDUE' => StateEnum::OVERDUE,
            'CANCELED' => StateEnum::CANCELED,
            'PAID' => StateEnum::PAID,
            'DERECOGNIZED' => StateEnum::DERECOGNIZED,
            'NOT_APPLICABLE' => StateEnum::NOT_APPLICABLE,
            default => StateEnum::CREATE, // Safe fallback (initial state)
        };

        $invoice->amount = (float)$sdkInvoice->getAmount();
        $invoice->taxAmount = (float)$sdkInvoice->getTaxAmount();
        $invoice->outstandingAmount = $sdkInvoice->getOutstandingAmount() !== null
            ? (float)$sdkInvoice->getOutstandingAmount()
            : null;

        $invoice->createdOn = $this->toDateTimeImmutable($sdkInvoice->getCreatedOn());
        $invoice->dueOn = $this->toDateTimeImmutable($sdkInvoice->getDueOn());
        $invoice->paidOn = $this->toDateTimeImmutable($sdkInvoice->getPaidOn());

        // The invoiced line items are the captured reality; map them onto the
        // existing LineItem domain objects.
        $sdkLineItems = $sdkInvoice->getLineItems();
        $invoice->lineItems = !empty($sdkLineItems)
            ? new LineItemCollection(...array_map([$this, 'mapToLineItem'], $sdkLineItems))
            : new LineItemCollection();

        return $invoice;
    }
}
