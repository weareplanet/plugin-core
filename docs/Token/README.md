# Token

A **Token** represents a customer's saved payment credentials, stored by the portal so a later transaction can be charged without the customer being present. This is the building block behind [Recurring Payments](../Recurring/README.md) (subscriptions, unscheduled follow-up charges).

## Key Components

- **Token**: The domain entity — `id`, `state`, `customerIdentifier` (a customer-facing identifier, e.g. a masked card number), `createdOn`, and `version`.
- **State**: The token's lifecycle — `CREATE`, `ACTIVE`, `INACTIVE`, `DELETING`, `DELETED`.
- **TokenizationMode**: Controls how a *transaction* requests tokenization at checkout (see below).
- **TokenService**: Explicitly creates/confirms a token for a transaction.
- **TokenGatewayInterface**: Abstraction for the underlying API interaction.

## Enabling Tokenization at Checkout

A token is only created if the *original* transaction was created with the right `TokenizationMode`, set on `TransactionContext` before the transaction is created:

```php
use WeArePlanet\PluginCore\Token\TokenizationMode;

$context->tokenizationMode = TokenizationMode::FORCE_CREATION;
```

The available modes:

- **`FORCE_CREATION`**: Always creates a token once the payment completes. Use this for recurring/MIT charges — see [Recurring Payments](../Recurring/README.md).
- **`FORCE_CREATION_WITH_ONE_CLICK_PAYMENT`**: Same as above, and additionally enables one-click payment for the customer.
- **`ALLOW_ONE_CLICK_PAYMENT`**: Enables one-click payment if the customer opts in, without forcing token creation.
- **`FORCE_UPDATE`**: Forces an update of an existing token's payment credentials (e.g. the customer re-entered their card details for a transaction already linked to a token).

> [!IMPORTANT]
> Tokenization cannot be enabled retroactively once a transaction has completed without it. If `tokenizationMode` isn't set at checkout, there is no token to charge later.

## Creating a Token Explicitly

`TokenService::createTokenForTransaction()` asks the API to create a token for a given transaction:

```php
use WeArePlanet\PluginCore\Token\TokenService;
use WeArePlanet\PluginCore\Token\Exception\TokenException;

try {
    $token = $tokenService->createTokenForTransaction($spaceId, $transactionId);
} catch (TokenException $e) {
    $logger->error("Token creation failed: " . ($e->getLocalizedMessage()?->localize('en-US') ?? $e->getMessage()));
}
```

This only succeeds if the transaction supports tokenization (i.e. it was created with one of the `TokenizationMode` values above) — it throws `MissingTokenException` (in `WeArePlanet\PluginCore\Token\Exception`, a subclass of `TokenException`) if the transaction has no associated token.

## Errors

- **`TokenException`**: Token creation failed at the API or transport level.
- **`MissingTokenException`**: A token was expected but is missing from the transaction — extends `TokenException`.

Like every other PluginCore exception, both expose `isRetryable()` — see [Error Handling](../ErrorHandling.md).
