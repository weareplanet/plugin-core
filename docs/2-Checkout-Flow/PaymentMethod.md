# Payment Method Retrieval

This page covers retrieving the payment methods a merchant has configured in the WeArePlanet Portal, and synchronizing them into your own storage.

## Overview

The `PaymentMethod` domain provides a consolidated entity for both retrieving the payment method **configurations** set up in a space (for admin/settings screens) and synchronizing those configurations into your own database.

> [!IMPORTANT]
> This is **not** what you use to decide which payment methods to offer a customer at checkout. `PaymentMethodService` returns every method configured in the space, regardless of whether a given transaction is actually eligible for it (currency, amount, customer country, and integration mode all affect eligibility). For checkout, use `TransactionService::getAvailablePaymentMethods()` instead — see [Checkout & Transaction Flow](Checkout.md#available-payment-methods).

The `$method->state` property is a strongly typed `WeArePlanet\PluginCore\PaymentMethod\State` enum. Use `$method->state->value` when you need the string representation (e.g., for display or database storage). The valid states are: `ACTIVE`, `CREATE`, `DELETED`, `DELETING`, and `INACTIVE`.

## Usage

To retrieve payment methods, use the `PaymentMethodService`.

```php
$paymentMethodService = new PaymentMethodService($gateway, $repository, $logger);

foreach ($paymentMethodService->getPaymentMethods($spaceId) as $method) {
    echo $method->title->getDefault() . " (ID: {$method->id})\n";
}
```

## Synchronizing Payment Methods

Use `getPaymentMethods()` when you need the space's configured methods on demand (e.g. an admin settings screen). Use `synchronize(int $spaceId): void` instead when you want to keep a local mirror of them — for example, in a cron job or an admin-triggered sync — so the rest of your plugin can query its own database instead of calling the API on every request.

Neither method is eligibility-aware; for what to actually offer a customer during checkout, see [`TransactionService::getAvailablePaymentMethods()`](Checkout.md#available-payment-methods).

`synchronize()` fetches the current methods from the WeArePlanet Portal, diffs them against what your plugin already has stored, and calls the matching operation on your repository — you never write the diffing logic yourself. To use it, implement `PaymentMethodRepositoryInterface`:

- **`getExistingExternalIds(int $spaceId): array`** — return the external IDs your plugin already has stored for this space. You can return either a plain list of IDs (`[100, 200]`), or, for smarter sync that skips methods that haven't actually changed, an `[externalId => signature]` map using `PaymentMethod::getSignature()`.
- **`create(PaymentMethod $method, int $spaceId): void`** — called for a method the API returned that you don't have yet.
- **`update(PaymentMethod $method, int $spaceId): void`** — called for a method you already have, when its signature changed (or when you're not using signatures).
- **`deactivateByExternalId(int $externalId, int $spaceId): void`** — called for a method you have stored that the API no longer returns.

```php
$paymentMethodService = new PaymentMethodService($gateway, $repository, $logger);
$paymentMethodService->synchronize($spaceId);
```

When it finishes, it logs a summary at `INFO` level — `created`, `updated`, `skipped`, and `deactivated` counts — so you can confirm a sync run without stepping through your repository's persistence code.

See [2_synchronize_payment_methods.php](../examples/2-Checkout-Flow/2_synchronize_payment_methods.php) for a full runnable script, including a sample repository implementation.

> [!TIP]
> The IDs you store here are **configuration** IDs. When you later resolve a customer's saved credentials back to one of these records, match `PaymentMethod::$id` against `TokenVersion::$paymentMethodConfigurationId` — never against `paymentMethodId`. See [Token](Token.md).

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

## Examples

- [retrieval.php](../examples/2-Checkout-Flow/1_list_payment_methods.php): Fetches and displays the available payment methods.
- [2_synchronize_payment_methods.php](../examples/2-Checkout-Flow/2_synchronize_payment_methods.php): Retrieves methods and synchronizes them into a sample repository (see [Synchronizing Payment Methods](#synchronizing-payment-methods) above).
