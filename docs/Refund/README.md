# Refund

The Refund feature allows merchants to refund generic transactions. Supports full refunds, partial refunds, and refunding specific line items.

## Overview

The Refund process involves:

1. **Validation**: Ensures the refund amount does not exceed the remaining authorized amount. Line item quantities and amounts are also validated.
2. **Creation**: A refund is created via the WeArePlanet SDK.
3. **Result**: A strict Domain Entity `Refund` is returned, containing state and ID.

## Key Components

- **RefundService**: The main entry point. Validates and executes refunds.
- **RefundContext**: A DTO containing all necessary data to create a refund (Amount, Line Items, Type, etc.).
- **RefundGatewayInterface**: Abstraction for the underlying API interaction.

## Partial Refunds & Price Reductions

When performing a partial refund on specific line items, it is crucial to understand how the **Reduction Amount** is calculated.

The field `amount` in the `lineItems` array of `RefundContext` corresponds to the **Unit Price Reduction**, NOT the total reduction for that item.

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

So you must pass `quantity: 0` and `amount: 10.00` in the line item context.

```php
$context = new RefundContext(
    transactionId: 123,
    amount: 20.00, // Total Refund Amount
    // ...
    lineItems: [
        [
            'uniqueId' => 'sku-123',
            'quantity' => 0,      // Returning 0 physical items
            'amount'   => 10.00   // Reducing unit price by 10.00 (x 2 items = 20.00)
        ]
    ]
);
```

## Usage

```php
use WeArePlanet\PluginCore\Refund\RefundService;
use WeArePlanet\PluginCore\Refund\RefundContext;
use WeArePlanet\PluginCore\Refund\Type;

// ... instantiate services ...

$context = new RefundContext(
    transactionId: 123,
    amount: 50.00,
    merchantReference: 'refund-ref-1',
    type: Type::MERCHANT_INITIATED_ONLINE,
    lineItems: [] // Optional: For partial specific items
);

try {
    $refund = $refundService->createRefund($spaceId, $context);
    echo "Refund created: " . $refund->id;
} catch (InvalidRefundException $e) {
    echo "Validation failed: " . $e->getMessage();
}
```

## Listing Refunds

You can retrieve all refunds associated with a transaction using `getRefunds`:

```php
$refunds = $refundService->getRefunds($spaceId, $transactionId);

foreach ($refunds as $refund) {
    echo "Refund ID: " . $refund->id;
    echo "State: " . $refund->state->value;
}
```

## Example

See the [example](example/) directory for a fully working CLI script that demonstrates:

1. Full Refund
2. Validation Error (Amount too high)
3. Partial Refund
