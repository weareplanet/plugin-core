# Error Handling

This page covers two cross-cutting patterns used throughout PluginCore: knowing whether a failure is worth retrying, and checking what a gateway state actually means.

## Retryable Exceptions

Every domain exception thrown by PluginCore extends `AbstractDomainException`, which exposes `isRetryable(): bool`.

- **`false` (the default):** The failure is terminal. It reflects a business-rule or validation error (e.g. `InvalidRefundException`), and retrying the exact same request will fail again — the request itself needs to change.
- **`true`:** The failure is transient (e.g. a network hiccup, or a concurrent update). Retrying the same request is expected to succeed.

```php
use WeArePlanet\PluginCore\Refund\Exception\RefundException;

try {
    $refund = $refundService->createRefund($spaceId, $context);
} catch (RefundException $e) {
    if ($e->isRetryable()) {
        // Safe to schedule a retry (e.g. queue a background job).
        $this->scheduleRetry($context);
    } else {
        // Terminal: surface the error to the merchant/customer instead of retrying.
        $this->reportFailure($e->getLocalizedMessage());
    }
}
```

`TransientWebhookException` — used when processing incoming webhooks — is always retryable, matching the portal's own webhook retry behavior.

## State Capability Predicates

State enums across PluginCore (e.g. `Refund\State`, `Transaction\State`, `Token\State`) expose two helper methods in addition to `canTransitionTo()`:

- **`isTerminal(): bool`** — true if this is a final, resolved state (e.g. `Refund\State::SUCCESSFUL`, `Refund\State::FAILED`).
- **`isPending(): bool`** — true if the state has not yet reached a final outcome (the inverse of `isTerminal()`).

Use these instead of comparing against a hardcoded list of cases, so your code stays correct if a domain later gains a new terminal or non-terminal state:

```php
use WeArePlanet\PluginCore\Refund\State as RefundState;

$refunds = $refundService->getRefunds($spaceId, $transactionId);

foreach ($refunds as $refund) {
    if ($refund->state->isPending()) {
        // Still awaiting a decision from the gateway — check back later.
        continue;
    }

    if ($refund->state->isTerminal() && $refund->state === RefundState::FAILED) {
        // Resolved, and it failed — surface it to the merchant.
    }
}
```
