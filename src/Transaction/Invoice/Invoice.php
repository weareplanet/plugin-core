<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Invoice;

use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * Read-only domain entity representing a Transaction Invoice.
 *
 * While a Transaction describes the payment *promise* (the cart at checkout),
 * the invoice describes the *captured reality*: the line items and amounts
 * that were actually invoiced to the customer. Shop plugins should use it to
 * derive accurate refundable line items and to check capture states.
 */
class Invoice
{
    use JsonStringableTrait;

    /**
     * @var float The invoiced amount including tax, in the transaction's currency.
     */
    public float $amount = 0.0;

    /**
     * @var \DateTimeImmutable|null The date/time when the invoice was created.
     */
    public ?\DateTimeImmutable $createdOn = null;

    /**
     * @var \DateTimeImmutable|null The date/time when the invoice is due.
     */
    public ?\DateTimeImmutable $dueOn = null;

    /**
     * @var int The invoice ID.
     */
    public int $id;

    /**
     * @var LineItemCollection The line items that were actually invoiced.
     */
    public LineItemCollection $lineItems;

    /**
     * @var int The space ID this invoice belongs to.
     */
    public int $linkedSpaceId;

    /**
     * @var int|null The ID of the transaction this invoice belongs to.
     */
    public ?int $linkedTransactionId = null;

    /**
     * @var float|null The amount still outstanding on the invoice.
     */
    public ?float $outstandingAmount = null;

    /**
     * @var \DateTimeImmutable|null The date/time when the invoice was paid.
     */
    public ?\DateTimeImmutable $paidOn = null;

    /**
     * @var State The strict state enum.
     */
    public State $state;

    /**
     * @var float The tax portion of the invoiced amount.
     */
    public float $taxAmount = 0.0;
}
