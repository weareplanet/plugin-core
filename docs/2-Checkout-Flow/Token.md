# Token

A **Token** is a secure reference to a customer's saved payment credentials — the equivalent of a saved credit card. It lets a later transaction be charged without the customer re-entering their details, or even being present at all.

## Why Use a Token?

- **One-Click Checkout:** A returning customer pays instantly, without re-entering card details, by reusing a token saved on an earlier order.
- **Recurring Payments:** The shop charges a saved token in the background — no browser, no customer present — for a subscription renewal (see [Recurring Payments](../4-Background-Tasks/Recurring.md)) or an admin-initiated MOTO order (see [Charge](Charge.md)).

## Key Components

- **Token**: The domain entity — `id`, `state`, `spaceId`, `customerId` (your shop's own customer reference), `customerIdentifier` (a customer-facing identifier, e.g. a masked card number), `createdOn`, and `version`. Provides `isChargeable()`.
- **TokenVersion**: One version of a token's credentials — `id`, `token`, `state`, `name`, `linkedSpaceId`, plus two pairs of references: `connectorId`/`paymentMethodId` (the global *types*) and `connectorConfigurationId`/`paymentMethodConfigurationId` (the merchant's space-scoped *configuration*). Provides `isActive()`.
- **State**: The token's lifecycle — `CREATE`, `ACTIVE`, `INACTIVE`, `DELETING`, `DELETED`.
- **Version\State**: A version's lifecycle — `UNINITIALIZED`, `ACTIVE`, `OBSOLETE`.
- **TokenizationMode**: Controls how a *transaction* requests tokenization at checkout (see Step 1 below).
- **TokenService**: Creates tokens, reads their versions, and deletes them.
- **TokenGatewayInterface**: Abstraction for the underlying API interaction.

Both `Token` and `TokenVersion` are **immutable** (`readonly`): every read returns a fresh instance, and writing to a property raises an `Error`. Store the values you need, or re-read to get the current state — an object you are holding will never change underneath you.

## Step 1: Requesting a Token at Checkout

A token is only created if the *original* transaction was created with the right `TokenizationMode`, set on `TransactionContext` before the transaction is created:

```php
use WeArePlanet\PluginCore\Token\TokenizationMode;

$context->tokenizationMode = TokenizationMode::FORCE_CREATION;
```

The available modes:

- **`FORCE_CREATION`**: Always creates a token once the payment completes. Use this for recurring/MIT charges — see [Recurring Payments](../4-Background-Tasks/Recurring.md).
- **`FORCE_CREATION_WITH_ONE_CLICK_PAYMENT`**: Same as above, and additionally enables one-click payment for the customer.
- **`ALLOW_ONE_CLICK_PAYMENT`**: Enables one-click payment if the customer opts in, without forcing token creation.
- **`FORCE_UPDATE`**: Forces an update of an existing token's payment credentials (e.g. the customer re-entered their card details for a transaction already linked to a token).

> [!IMPORTANT]
> Tokenization cannot be enabled retroactively once a transaction has completed without it. If `tokenizationMode` isn't set at checkout, there is no token to charge later.

## Step 2: Creating the Token

Once the payment completes, `TokenService::createTokenForTransaction()` turns the credentials the customer just used into a saved, chargeable token — linked to the customer via the `customerId` you set on the original `TransactionContext`, if you supplied one:

```php
try {
    $token = $tokenService->createTokenForTransaction($spaceId, $transactionId);
} catch (TokenException $e) {
    // MissingTokenException (a TokenException) when the transaction has no token.
}
```

👉 **See this in action:** [token_management.php](../examples/2-Checkout-Flow/token_management.php)

This only succeeds if the transaction supports tokenization (i.e. it was created with one of the `TokenizationMode` values above) — it throws `MissingTokenException` (in `WeArePlanet\PluginCore\Token\Exception`, a subclass of `TokenException`) if the transaction has no associated token.

## Step 3: Managing Tokens

### The Wallet Analogy

Think of a `Token` as the permanent **slot** in a customer's wallet — a stable identity that never changes. The credentials sitting in that slot can be swapped out over time (a customer updates an expiring card, for instance); each set of credentials is a **`TokenVersion`**, and **at most one version of a token is active at a time**. Older versions become obsolete but stay readable, which is what makes a historical charge explainable later.

```php
// The version currently in force — null when the token has none.
$version = $tokenService->getActiveTokenVersion($spaceId, $tokenId);

// Or one specific version by its own ID — null when no such version exists.
$version = $tokenService->getTokenVersion($spaceId, $tokenVersionId);
```

👉 **See this in action:** [token_management.php](../examples/2-Checkout-Flow/token_management.php)

Both lookups return `null` rather than throwing when the version does not exist: asking for a version a token does not have is an ordinary question. A genuine failure — an unreachable or rejecting API — still throws `TokenException`.

All four references on `TokenVersion` are IDs, not embedded configuration objects, so a lookup costs one API call on every API version. Resolve the full configuration through the [Payment Method](PaymentMethod.md) feature when you need it.

### Deleting a Token

When a customer withdraws consent or removes a saved payment method, delete the token:

```php
$tokenService->deleteToken($spaceId, $tokenId);
```

> [!IMPORTANT]
> Deletion is permanent. The stored credentials become unusable for **any** future charge, including recurring ones you have already scheduled — cancel or re-authorize those separately. There is no undo, and no way to recover the credentials afterwards; the customer would have to enter them again at a new checkout.

`deleteToken()` returns nothing and throws `TokenException` if the token cannot be deleted, including when no token with that ID exists.

## Advanced: Data Mapping & Display

### Type IDs versus configuration IDs

The two pairs on `TokenVersion` answer different questions, and picking the wrong one fails quietly rather than loudly:

| Property | Scope | Identifies |
|---|---|---|
| `connectorId` | Global | The connector *type* — the same ID every space sees, as listed by [Global Data](../1-Getting-Started/GlobalData.md). |
| `paymentMethodId` | Global | The payment method *type* (e.g. "credit card"), not any particular setup of it. |
| `connectorConfigurationId` | Space-scoped | The connector configuration the credentials were created under. |
| `paymentMethodConfigurationId` | Space-scoped | The payment method configuration the credentials were created under — one merchant's setup, such as "Visa via a particular acquirer". |

> [!IMPORTANT]
> `PaymentMethod::$id` is a **configuration** ID. It matches `paymentMethodConfigurationId`, *not* `paymentMethodId`. A plugin that keys its locally synced payment-method records by `PaymentMethod::$id` — the [Payment Method](PaymentMethod.md) sync pattern — and then joins them on `paymentMethodId` will match the wrong record or none at all, without raising an error.

> [!WARNING]
> The property name `paymentMethodId` does **not** mean the same thing everywhere. On `TokenVersion` it is the payment method *type*; on `Transaction`'s `paymentMethod` snapshot (`TransactionPaymentMethod::$paymentMethodId`, see [Checkout](Checkout.md)) it is the *configuration* ID — the equivalent of `paymentMethodConfigurationId` here. Check which entity you are holding before using either as a key.

This is what a plugin uses to resolve a stored token back to the merchant-configured payment method it was created under, e.g. to render the right saved-card label and icon:

```php
// Which of the merchant's configured payment methods is this token bound to?
foreach ($paymentMethodService->getPaymentMethods($spaceId) as $method) {
    // PaymentMethod::$id is a *configuration* ID: it matches paymentMethodConfigurationId.
    $isMatch = $method->id === $version->paymentMethodConfigurationId;
}
```

All four are nullable: a payload that carries no connector configuration maps to nulls rather than failing the read, so check before using one as a key.

### Identifying the Customer a Token Belongs To

`Token` carries two customer fields. They look similar and are not interchangeable:

| Field | What it is | Use it for |
|---|---|---|
| `customerId` | Your shop's own customer reference, supplied when the token was created and echoed back by the WeArePlanet Portal. | Keying: deciding which tokens belong to which customer. |
| `customerIdentifier` | A customer-facing label, derived from the email address or the token reference. | Display: telling one saved card apart from another on screen. |

This distinction matters on the screen where a customer manages their saved payment methods. Scoping that list by `customerIdentifier` risks showing one customer another's saved cards — two customers can share an email address, and the value is a display string with no guarantee of uniqueness. `customerId` is the key that was actually supplied for that purpose:

```php
// Showing a customer their own saved payment methods.
$mine = array_filter(
    $tokens,
    static fn (Token $token): bool => $token->customerId === $shopCustomerId,
);
```

Both are nullable. `customerId` is `null` when the token was created without a customer reference — a guest checkout that still tokenized, typically — so a token with no `customerId` belongs to no customer and must not fall into anyone's list.

## Errors

- **`TokenException`**: Token creation failed at the API or transport level.
- **`MissingTokenException`**: A token was expected but is missing from the transaction — extends `TokenException`.

Like every other PluginCore exception, both expose `isRetryable()` — see [Error Handling](../1-Getting-Started/ErrorHandling.md).
