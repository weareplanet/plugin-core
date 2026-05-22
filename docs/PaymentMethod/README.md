# Payment Method Retrieval

This directory contains documentation and examples for retrieving available payment methods.

## Overview

The `PaymentMethod` domain provides a consolidated entity for both retrieving available payment methods (for display) and synchronizing payment method configurations from the portal.

The `$method->state` property is a strongly typed `WeArePlanet\PluginCore\PaymentMethod\State` enum. Use `$method->state->value` when you need the string representation (e.g., for display or database storage). The valid states are: `ACTIVE`, `CREATE`, `DELETED`, `DELETING`, and `INACTIVE`.

## Usage

To retrieve payment methods, use the `PaymentMethodService`.

```php
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodService;

// ... setup dependencies ...

$paymentMethodService = new PaymentMethodService($gateway, $logger);
$paymentMethods = $paymentMethodService->getPaymentMethods($spaceId);

foreach ($paymentMethods as $paymentMethod) {
    echo "Payment Method: " . $paymentMethod->name . " (ID: " . $paymentMethod->id . ")\n";
}
```

## Utility Methods

The `PaymentMethod` entity includes convenience methods to reduce string-manipulation boilerplate in client plugins.

### `getRelativeImagePath(): string`

The API returns absolute image URLs containing a `/resource/` path segment and query parameters for cache busting (e.g., `https://paymentshub.weareplanet.com/s/123/resource/payment/method/twint.svg?strategy=snapshot`). Use this method to extract just the clean relative file path (`payment/method/twint.svg`) for proxying, downloading, or mapping images natively.

```php
foreach ($paymentMethods as $method) {
    // Returns 'payment/method/twint.svg' — no manual string-stripping needed.
    $relativePath = $method->getRelativeImagePath();
}
```

Returns an empty string if `imageUrl` is `null`, and falls back to the full URL (minus query parameters) if no `resource/` segment is found.

## Example

See the [example](example/) directory for a working script that fetches and displays the available payment methods.
