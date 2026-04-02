# Payment Method Retrieval

This directory contains documentation and examples for retrieving configured payment methods.

## Overview

The `PaymentMethod` domain provides a service for retrieving configured payment methods from the portal.

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

## Example

See the [example](example/) directory for a working script that fetches and displays the available payment methods.
