# Transaction Invoice (Reading the Captured Reality)

The **Invoice** sub-domain (`Transaction\Invoice`) gives shop plugins read-only access to the invoice generated for a transaction. It lives under the Completion feature because the invoice is the direct outcome of a capture.

## Why it exists

A `Transaction` represents the payment **promise**: the cart as it looked when the customer checked out. The `Invoice` represents the **captured reality**: the line items and amounts that were actually invoiced (and possibly adjusted) during completion.

Shop plugins should use the invoice — not the original transaction — to:

* Calculate **accurate refundable line items** (partial captures reduce what is refundable).
* Check **capture states** (`PAID`, `OPEN`, `DERECOGNIZED`, ...) before enabling order actions.

## Key Components (`WeArePlanet\PluginCore\Transaction\Invoice`)

* **`Invoice`**: Read-only DTO — `id`, `linkedSpaceId`, `linkedTransactionId`, `state`, `amount`, `taxAmount`, `outstandingAmount`, `lineItems` (a `LineItemCollection` of the standard `LineItem` domain objects), and the `createdOn`/`dueOn`/`paidOn` timestamps.
* **`State`**: Strict enum of the invoice lifecycle: `CREATE`, `OPEN`, `OVERDUE`, `CANCELED`, `PAID`, `DERECOGNIZED`, `NOT_APPLICABLE` (with a full transition map).
* **`InvoiceSearchCriteria`**: Filter/sort/limit DTO for searches.
* **`InvoiceGatewayInterface`**: The read-only gateway abstraction.

> **Note:** Invoice amounts are expressed in the transaction's currency; the invoice itself carries no currency field.

## Gateway Methods

Following the core naming conventions:

| Method | Behavior |
|---|---|
| `get(int $spaceId, int $invoiceId): Invoice` | Returns the invoice, **throws** `InvoiceException` if it cannot be read. |
| `find(int $spaceId, int $invoiceId): ?Invoice` | Returns the invoice or **`null`** when it does not exist (404). |
| `search(int $spaceId, InvoiceSearchCriteria $criteria): InvoiceCollection` | Returns a collection matching the criteria. |

All methods throw an `InvoiceException` when the request fails.

## Finding the invoice for a transaction

The invoice is linked to its transaction through the completion. Search with the
`completion.lineItemVersion.transaction.id` field:

```php
use WeArePlanet\PluginCore\Transaction\Invoice\InvoiceSearchCriteria;

$criteria = new InvoiceSearchCriteria(
    limit: 1,
    filters: ['completion.lineItemVersion.transaction.id' => $transactionId],
);
$invoice = $invoiceGateway->search($spaceId, $criteria)->first();
```

## Example

See [example/fetch_invoice.php](example/fetch_invoice.php) for a working CLI script that locates the invoice of a transaction, prints its state and amounts, and iterates the invoiced line items.

## Used by Refunds

Invoices are the source of truth behind `RefundService::getRefundableLineItems()` — it resolves the invoiced line items (via the latest successful refund's reduced state, or this invoice's line items when no refund exists yet) so callers never have to reason about invoices directly. See the **[Refund documentation](../Refund/README.md)** for the full partial-refund calculation, including why `LineItem::$unitPriceIncludingTax` must be used instead of dividing.
