# Delivery Indication

After a payment is captured, the WeArePlanet Portal may raise a **delivery indication**: a request for the merchant to decide whether the order is safe to ship. Until it is decided, the order is effectively held for review — this is the mechanism behind an order sitting in a "payment review" state.

`DeliveryIndicationService` lets a plugin read an indication and record that decision.

## Key Components

- **DeliveryIndicationService**: The entry point consumers use — `findByTransaction()`, `get()`, `markAsSuitable()` and `markAsNotSuitable()`.
- **DeliveryIndicationGatewayInterface**: The infrastructure port the service delegates to. Consumers do not call it directly.
- **DeliveryIndication**: the indication itself — `id`, `spaceId`, `state`, `transactionId`, `completionId`, plus `isDecisionPending()`.
- **State**: an enum of the possible states — `PENDING`, `MANUAL_CHECK_REQUIRED`, `SUITABLE`, `NOT_SUITABLE`.

## Usage

```php
$indication = $indicationService->get($spaceId, $indicationId);

if ($indication->isDecisionPending()) {
    $decided = $indicationService->markAsSuitable($spaceId, $indicationId);
} // ...or markAsNotSuitable() to reject it. Both return the updated indication.
```

👉 **See this in action:** [delivery_indication.php](../examples/2-Checkout-Flow/delivery_indication.php)

Each method returns the indication as it stands after the call, so there is no need to re-read it to learn the new state.

### Starting from a transaction

Plugins usually store the transaction ID against the order, not the indication ID — the WeArePlanet Portal assigns that. `findByTransaction()` bridges that gap:

```php
// null when the payment is not captured, or was never selected for review.
$indication = $indicationService->findByTransaction($spaceId, $transactionId);

$indication?->isDecisionPending(); // then markAsSuitable() / markAsNotSuitable()
```

👉 **See this in action:** [delivery_indication.php](../examples/2-Checkout-Flow/delivery_indication.php)

A transaction has at most one delivery indication, so this returns a single indication rather than a collection.

**The return type is nullable, and `null` is not an error.** Most transactions never get an indication at all — one is only raised for a captured payment the WeArePlanet Portal decides to hold for review — so an empty result is the common path and is reported as `null` rather than as an exception. You do not need a `try`/`catch` to handle "this order has nothing to review"; reserve that for genuine failures such as an unreachable API.

### Parameter order

Every method takes the **space first** — `(int $spaceId, int $indicationId)`, and `(int $spaceId, int $transactionId)` for `findByTransaction()`. Both arguments are integers, so a transposed call would not be caught by PHP or by static analysis and would silently address the wrong entity. Keep the arguments in this order at every call site.

### Deciding only once

A decision is final: `PENDING` and `MANUAL_CHECK_REQUIRED` can be decided, `SUITABLE` and `NOT_SUITABLE` cannot. Marking an already-decided indication is rejected by the WeArePlanet Portal and surfaces as a `DeliveryIndicationException`. Use `isDecisionPending()` to decide whether to offer the action at all.

### Indications and completions

An indication is raised against a *completion* (a capture), so one only exists for a captured payment — an authorized-but-uncaptured transaction has none. `completionId` and `transactionId` reference the related entities; resolve them through the [Completion](../3-Post-Payment/Completion.md) feature when you need the full objects.

## Errors

All service methods throw `DeliveryIndication\Exception\DeliveryIndicationException` when the operation cannot be carried out — an unknown indication, an already-decided indication, or an unreachable API. Like every other PluginCore exception, it exposes `isRetryable()` — see [Error Handling](../1-Getting-Started/ErrorHandling.md).

The one deliberate exception is an empty `findByTransaction()` result, which returns `null` instead, as described above.

## Reacting to Indications

Rather than polling, react to indications as the WeArePlanet Portal raises them via a webhook listener. See [Webhook Processor](../4-Background-Tasks/Webhook-Processor.md); the delivery indication listener reports the same `State` values used here.

A pending indication is work waiting on the merchant. To show how much of that work is outstanding across the whole space rather than per order, see [Manual Task](../4-Background-Tasks/ManualTask.md).
