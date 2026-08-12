# Error Handling

This page covers two cross-cutting patterns used throughout PluginCore: knowing whether a failure is worth retrying, and checking what a gateway state actually means.

## Retryable Exceptions

Every domain exception thrown by PluginCore extends `AbstractDomainException`, which exposes `isRetryable(): bool`.

- **`false` (the default):** The failure is terminal. It reflects a business-rule or validation error (e.g. `InvalidRefundException`), and retrying the exact same request will fail again — the request itself needs to change.
- **`true`:** The failure is transient (e.g. a network hiccup, or a concurrent update). Retrying the same request is expected to succeed.

A failure where the request never reached the WeArePlanet Portal — a connection error — is reported as retryable by **every** domain, not just some of them. Individual gateways add the transient causes only they can recognise: `TransactionGateway`, for instance, also treats a version conflict from a concurrent update as retryable, because re-reading and retrying resolves it.

```php
try {
    $refund = $refundService->createRefund($spaceId, $context);
} catch (RefundException $e) {
    $e->isRetryable() ? $this->scheduleRetry($context) : $this->reportFailure($e);
}
```

👉 **See this in action:** [error_handling.php](../examples/1-Getting-Started/error_handling.php)

`TransientWebhookException` — used when processing incoming webhooks — is always retryable, matching the WeArePlanet Portal's own webhook retry behavior.

## State Capability Predicates

State enums across PluginCore (e.g. `Refund\State`, `Transaction\State`, `Token\State`) expose two helper methods in addition to `canTransitionTo()`:

- **`isTerminal(): bool`** — true if this is a final, resolved state (e.g. `Refund\State::SUCCESSFUL`, `Refund\State::FAILED`).
- **`isPending(): bool`** — true if the state has not yet reached a final outcome (the inverse of `isTerminal()`).

Use these instead of comparing against a hardcoded list of cases, so your code stays correct if a domain later gains a new terminal or non-terminal state:

```php
// Predicates replace hardcoded enum comparisons.
$refund->state->isPending();   // Awaiting a decision from the gateway — check back later.
$refund->state->isTerminal();  // Resolved: it will not change again.
```

👉 **See this in action:** [error_handling.php](../examples/1-Getting-Started/error_handling.php)

## Making a Failure Traceable

When a failure is terminal and you report it, whoever picks up the support case needs to know which installation produced it. Naming your shop system and plugin version on every API call is what makes that possible — see [Plugin Identification](PluginIdentification.md).
