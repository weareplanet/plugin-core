# Refund

The Refund feature allows merchants to refund generic transactions. Supports full refunds, partial refunds, and refunding specific line items.

## Overview

The Refund process involves:

1. **Validation**: Ensures the refund amount does not exceed the remaining authorized amount. Line item quantities and amounts are also validated.
2. **Creation**: A refund is created via the WeArePlanet SDK.
3. **Result**: A strict Domain Entity `Refund` is returned, containing the state and ID and — when the gateway reports a failure — a localized `failureReason` plus `createdOn` / `failedOn` timestamps.

## Key Components

- **RefundService**: The main entry point. Validates and executes refunds, and computes what remains refundable (`getRefundableLineItems`).
- **RefundContext**: A DTO containing all necessary data to create a refund (Amount, Line Items, Type, etc.).
- **RefundGatewayInterface**: Abstraction for the underlying API interaction. Also supports direct lookups (`findById`, `findByTransaction`).

## Partial Refunds & Price Reductions

When performing a partial refund on specific line items, it is crucial to understand how the **Reduction Amount** is calculated.

`RefundContext::$lineItems` is a `RefundLineItemCollection` of `RefundLineItem` objects. The `unitPriceReduction` property on `RefundLineItem` corresponds to the **Unit Price Reduction**, NOT the total reduction for that item.

**Formula:**

```text
Total Reduction = (Quantity Returned * Unit Price) + (Remaining Quantity * Unit Price Reduction)
```

**Example:**
You sold 2 items of "Swiss Watch" at 150.00 each. You want to refund 20.00 total as a discount/adjustment, without returning any items.

- **Quantity Returned**: 0
- **Remaining Quantity**: 2
- **Desired Total Refund**: 20.00

Calculation for `Unit Price Reduction`:
`20.00 = (0 * 150.00) + (2 * X)` -> `20.00 = 2X` -> `X = 10.00`

So you must pass `returnedQuantity: 0` and `unitPriceReduction: 10.00` on the `RefundLineItem`.

```php
use WeArePlanet\PluginCore\Refund\LineItem\RefundLineItem;
use WeArePlanet\PluginCore\Refund\LineItem\RefundLineItemCollection;

$context = new RefundContext(
    transactionId: 123,
    amount: 20.00, // Total Refund Amount
    // ...
    lineItems: new RefundLineItemCollection(
        new RefundLineItem(
            uniqueId: 'sku-123',
            returnedQuantity: 0,      // Returning 0 physical items
            unitPriceReduction: 10.00, // Reducing unit price by 10.00 (x 2 items = 20.00)
        ),
    ),
);
```

## Avoiding Floating-Point Errors: Use `unitPriceIncludingTax`

The formula above requires a **per-unit price**. Do **not** derive it by dividing `$lineItem->amountIncludingTax / $lineItem->quantity` — floating-point division introduces rounding errors (e.g. `29.99 / 3 = 9.996666...`) that the gateway API will reject when the reduction amounts don't reconcile exactly with the total.

`LineItem` exposes the unit price directly, exactly as reported by the API:

```php
public float $unitPriceIncludingTax;
```

Always use `$lineItem->unitPriceIncludingTax` when calculating a per-item reduction — never division.

**Where do these line items come from?** Don't read them from the original `Transaction`. A transaction can be partially captured, and the original cart doesn't reflect that. Fetch the **[Transaction Invoice](../Completion/Invoice.md)** — the captured reality — either directly via the `InvoiceGateway`, or through `RefundService::getRefundableLineItems()`, which resolves it for you (see below).

See **[example/refund_quantity_and_stock.php](example/refund_quantity_and_stock.php)** for the full, correct pattern end to end.

## Usage

```php
use WeArePlanet\PluginCore\Refund\RefundService;
use WeArePlanet\PluginCore\Refund\RefundContext;
use WeArePlanet\PluginCore\Refund\Type as TypeEnum;
use WeArePlanet\PluginCore\Refund\Exception\InvalidRefundException;
use WeArePlanet\PluginCore\Refund\Exception\RefundException;

// ... instantiate services ...

$context = new RefundContext(
    transactionId: 123,
    amount: 50.00,
    merchantReference: 'refund-ref-1',
    type: TypeEnum::MERCHANT_INITIATED_ONLINE,
    // lineItems defaults to an empty RefundLineItemCollection: for partial, specific-item
    // refunds, pass a RefundLineItemCollection of RefundLineItem objects instead.
);

try {
    $refund = $refundService->createRefund($spaceId, $context);
    echo "Refund created: " . $refund->id . " (" . $refund->state->value . ")";

    // The gateway may return a FAILED refund carrying a localized failure reason.
    if ($refund->failureReason !== null) {
        echo "Failure reason: " . $refund->failureReason->localize('en-US');
    }
} catch (InvalidRefundException $e) {
    // Thrown before the API call when the request violates a business rule.
    echo "Validation failed: " . $e->getMessage();
} catch (RefundException $e) {
    // Thrown when the gateway/API rejects the refund; carries a localized reason.
    echo "Refund failed: " . ($e->getLocalizedMessage()?->localize('en-US') ?? $e->getMessage());
}
```

## Listing Refunds

You can retrieve all refunds associated with a transaction using `getRefunds`:

```php
$refunds = $refundService->getRefunds($spaceId, $transactionId);

foreach ($refunds as $refund) {
    echo "Refund ID: " . $refund->id;
    echo "State: " . $refund->state->value;

    // A FAILED refund exposes its localized reason and the time it failed.
    if ($refund->failureReason !== null) {
        echo "Failure reason: " . $refund->failureReason->localize('en-US');
        echo "Failed on: " . $refund->failedOn?->format(\DATE_ATOM);
    }
}
```

## Fetching a Single Refund (Webhooks)

Webhook payloads (e.g., a `Refund` state change) carry a **refund ID but no transaction ID**. See [Webhook Processor](../Webhook/Processor/README.md) for handling the incoming notification itself. Use the gateway's `findById` to resolve the full refund directly:

```php
$refund = $refundGateway->findById($spaceId, $refundId);

echo "Refund ID: " . $refund->id;
echo "Transaction ID: " . $refund->transactionId; // resolved from the API payload
echo "State: " . $refund->state->value;

// The refunded line items (reductions), when reported by the API:
foreach ($refund->lineItems ?? [] as $item) {
    echo $item->name . ": " . $item->amountIncludingTax;
}
```

Errors throw a `RefundException`.

## Determining What Remains Refundable

Shop plugins should not calculate remaining refundable items manually. The API reports the **post-refund cart state** on every refund (`$refund->reducedLineItems`), and `RefundService` turns that into a one-call state engine:

```php
$refundable = $refundService->getRefundableLineItems($spaceId, $transactionId);

foreach ($refundable as $item) {
    echo $item->name . ": " . $item->amountIncludingTax . " (qty " . $item->quantity . ")";
}
```

How it resolves the state:

1. The **most recent SUCCESSFUL refund** wins — its `reducedLineItems` already describe what remains (compared by `createdOn`, falling back to the sequential ID).
2. If no successful refund exists yet, the **original transaction cart** is returned.
3. If a successful refund exists but the API did not report its reduced state, an **empty collection** is returned — understating is the safe failure mode.

In every case, discounts and zero/negative-amount items are filtered out, since they cannot be refunded individually.

## Example Scripts

The [example](example/) directory contains two separate scripts demonstrating different refund models:

### General Lifecycle and Amount-Based Refunds
Refer to **[example/refund_lifecycle_and_amount.php](example/refund_lifecycle_and_amount.php)** to see general transaction-level flows, including:
- Attempting a refund greater than the authorized amount to test validation logic.
- Initiating flat price-reduction adjustments on line items without returning inventory (`quantity: 0`).
- Refunding the full remaining transaction balance.
- Fetching single refunds by ID (critical for webhook handlers that receive a refund ID but no transaction context).
- Listing current transaction refunds and remaining refundable items.

### Quantity and Stock-Returning Refunds
Refer to **[example/refund_quantity_and_stock.php](example/refund_quantity_and_stock.php)** for the safe pattern when returning physical units of a line item (returning stock, `quantity > 0`). This covers:
- Sourcing items correctly from the invoice-backed refundable state via `getRefundableLineItems()`.
- Safe calculation of the total reduction using the `unitPriceIncludingTax` field to prevent float division rounding issues that would cause API rejections.
