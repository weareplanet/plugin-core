# Refund

A **refund** returns money to the customer against a transaction that has already been captured. Three shapes are supported:

- **Full refund** — the entire remaining balance.
- **Partial, flat-amount refund** — a fixed sum off the transaction, with no particular item attached.
- **Line-item refund** — specific items, either *returning stock* (the customer sends goods back) or as a *price reduction* (they keep the goods, you refund some money).

## Key Components

- **RefundService**: The entry point — validates and executes refunds, computes what remains refundable, and reads refunds back.
- **RefundContext**: A DTO describing the refund to create (amount, line items, type).
- **Refund**: The result — `id`, `transactionId`, `state`, and on failure a localized `failureReason` plus `createdOn` / `failedOn` timestamps.

## Step 1: Determining What Is Refundable

Before refunding anything, ask what is still refundable. Don't compute this yourself, and don't read the line items off the original `Transaction` — a transaction can be partially captured, so the original cart doesn't reflect what was actually charged.

`getRefundableLineItems()` gives you the current post-refund cart state in one call, resolved from the [Transaction Invoice](Invoice.md) and any refunds already issued:

```php
$refundable = $refundService->getRefundableLineItems($spaceId, $transactionId);

foreach ($refundable as $item) {
    echo $item->name . ": " . $item->amountIncludingTax . " (qty " . $item->quantity . ")";
}
```

Discounts and zero/negative-amount items are filtered out, since they cannot be refunded individually. When the state cannot be determined, an empty collection is returned — understating what is refundable is the safe failure mode.

👉 **See this in action:** [refund_quantity_and_stock.php](../examples/3-Post-Payment/refund_quantity_and_stock.php)

## Step 2: Creating the Refund

Build a `RefundContext` and hand it to the service. Omit `lineItems` for a flat-amount refund; pass a `RefundLineItemCollection` to refund specific items (see [Advanced](#advanced-partial-refunds--price-reductions) below):

```php
// lineItems defaults to an empty collection: a flat-amount refund.
$context = new RefundContext(transactionId: 123, amount: 50.00, type: Type::MERCHANT_INITIATED_ONLINE);

$refund = $refundService->createRefund($spaceId, $context);
```

The service validates the request before calling the API — an amount exceeding the remaining balance, or line item quantities that don't reconcile, throw `InvalidRefundException` without a network round trip. A rejection from the API throws `RefundException`, carrying a localized reason.

👉 **See this in action:** [refund_lifecycle_and_amount.php](../examples/3-Post-Payment/refund_lifecycle_and_amount.php)

## Step 3: Handling & Tracking Refunds

Refunds resolve asynchronously, so a refund you just created may still be pending. List every refund on a transaction with `getRefunds()`:

```php
foreach ($refundService->getRefunds($spaceId, $transactionId) as $refund) {
    echo $refund->state->value;
    echo $refund->failureReason?->localize('en-US'); // set on a FAILED refund
    echo $refund->failedOn?->format(\DATE_ATOM);
}
```

A refund webhook, however, carries a **refund ID and no transaction ID** — so `getRefunds()` is no help there. `findById()` is what resolves the rest of the record, including the transaction it belongs to:

```php
$refund = $refundService->findById($spaceId, $refundId);

echo $refund->transactionId; // resolved from the API payload
echo $refund->state->value;
```

See [Webhook Processor](../4-Background-Tasks/Webhook-Processor.md) for handling the incoming notification itself.

👉 **See this in action:** [refund_lifecycle_and_amount.php](../examples/3-Post-Payment/refund_lifecycle_and_amount.php)

## Advanced: Partial Refunds & Price Reductions

`RefundContext::$lineItems` holds `RefundLineItem` objects, each carrying a `returnedQuantity` and a `unitPriceReduction`. The crucial detail: `unitPriceReduction` is the reduction **per unit**, not the total for that line.

```text
Total Reduction = (Quantity Returned * Unit Price) + (Remaining Quantity * Unit Price Reduction)
```

So to refund 20.00 across 2 units of a 150.00 item *without* taking stock back, set `returnedQuantity: 0` and `unitPriceReduction: 10.00` — because `20.00 = 2 × 10.00`:

```php
$context = new RefundContext(
    transactionId: 123,
    amount: 20.00,
    lineItems: new RefundLineItemCollection($refundLineItem), // returnedQuantity 0 = price reduction
);
```

### Use `unitPriceIncludingTax`, Never Division

That formula needs a per-unit price. Do **not** derive it as `$lineItem->amountIncludingTax / $lineItem->quantity` — floating-point division introduces rounding errors (`29.99 / 3 = 9.996666...`) that the API rejects when the reduction amounts don't reconcile exactly with the total.

`LineItem::$unitPriceIncludingTax` carries the unit price directly, exactly as the API reported it. Always use it.

👉 **See this in action:** [refund_quantity_and_stock.php](../examples/3-Post-Payment/refund_quantity_and_stock.php)

## Errors

- **`InvalidRefundException`**: The request violated a business rule and was rejected locally, before any API call.
- **`RefundException`**: The API or transport rejected the refund.

Like every other PluginCore exception, both expose `isRetryable()` — see [Error Handling](../1-Getting-Started/ErrorHandling.md).
