# Charge

Charging a transaction, and reading how the charge went. For most plugins this page is about the *second* half only — most integrations never call the "charging" API directly, but every integration eventually needs to read what happened.

## What Is a Charge Flow?

A **charge flow** is an automated state machine hosted by the WeArePlanet Portal. Once a transaction is confirmed, the flow decides *how* the WeArePlanet Portal tries to collect the money — silently charging a saved token, generating an invoice to pay asynchronously, emailing the customer a payment link, or another method entirely, depending on how the space is configured. PluginCore does not implement this logic; it only asks the WeArePlanet Portal to run the flow, and later reports what the flow produced.

## When You Don't Need `applyFlow()` (Implicit Flows)

For a standard customer-facing checkout — **Payment Page**, **Iframe**, or **Lightbox** — the WeArePlanet Portal triggers the space's default charge flow **automatically** the moment the customer completes the payment widget. Your plugin never calls `applyFlow()` in this path; it only needs to read the outcome afterward (see [Reading Charge Attempts](#reading-charge-attempts) below).

This is the same "implicit confirmation" behavior described in [Checkout: Confirmation](Checkout.md#core-concepts) — confirming and charging happen together, as a single step on the WeArePlanet Portal's side, with no explicit call from your code.

## When You Do Need `applyFlow()` (Explicit Flows)

### MOTO and Admin-Created Orders

The explicit case is a flow **without a customer-facing widget** — a shop admin placing a telephone order (MOTO) using a customer's saved card, for example. There is no browser to trigger anything implicitly, so your plugin drives both steps itself:

1. **Confirm** the transaction server-to-server — see [Confirming the Transaction](Checkout.md#confirming-the-transaction) and [5_confirm_manual.php](../examples/2-Checkout-Flow/5_confirm_manual.php).
2. **Apply the charge flow**, which is what actually collects the money:

```php
$transaction = $gateway->confirm($spaceId, $transactionId);
$charged = $charge->applyFlow($spaceId, $transactionId);
```

Skipping step 2 leaves the order confirmed but never charged — nothing else triggers the flow for you once the widget is out of the picture.

### Related: Recurring Payments

Charging a saved token for a subscription renewal or an unscheduled follow-up charge is a related but separate flow, handled by its own `RecurringTransactionService::processRecurringPayment()` rather than by `ChargeService::applyFlow()` directly — see [Recurring Payments](../4-Background-Tasks/Recurring.md) for that mechanism.

## What Is a Charge Attempt?

Applying a charge flow doesn't always succeed on the first try. A single flow can produce **multiple attempts**: a card is declined, so the flow emails the customer a payment link, and they succeed with a different card on a second attempt. Each attempt is recorded separately, so you can see not just whether the transaction is paid, but the story of how it got there.

## Key Components

- **ChargeService**: The entry point consumers use. Three methods:
  - `applyFlow(int $spaceId, int $transactionId): Transaction` — charges the transaction by applying its configured charge flow.
  - `findAllAttemptsByTransaction(int $spaceId, int $transactionId): array` — every attempt of the transaction, as `list<ChargeAttempt>`.
  - `findSuccessfulAttemptByTransaction(int $spaceId, int $transactionId): ?ChargeAttempt` — the successful attempt, or `null` when there is none.
- **ChargeGatewayInterface**: The API-facing operations — `applyFlow()` and `findAllAttemptsByTransaction()` — implemented once per API version.
- **Attempt\ChargeAttempt**: The attempt itself — `id`, `state`, and its `labels`. Provides `isSuccessful(): bool`, plus `getLabel(int $descriptorId): ?Label` and `getLabelsByGroup(string $groupId): array` so you can pick labels out without walking the array yourself.
- **Attempt\Label**: One reported detail — `descriptorId`, `content`, and the descriptor group it belongs to (`groupId`, plus `groupName` for display where the API provides it).

## Charging a Transaction

`applyFlow()` asks the WeArePlanet Portal to process a transaction through its configured charge flow. Wire the service up once — typically in your plugin's DI container — and inject it wherever a charge is triggered:

```php
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\ChargeGateway;

$charge = new ChargeService(new ChargeGateway($sdkProvider, $logger), $logger);
$transaction = $charge->applyFlow($spaceId, $transactionId);
```

> [!IMPORTANT]
> The charge flow runs **asynchronously**. The returned `Transaction` is the state at the moment the flow was applied — typically still `PROCESSING` — not the final outcome of the charge. To learn how the charge ended, read the transaction again later or handle the corresponding [webhook](../4-Background-Tasks/README.md).

The two API versions disagree about where this operation lives and what order its arguments come in. That difference stops at the gateway: the contract above is identical on both.

## Reading Charge Attempts

The most common reason to read a charge attempt has nothing to do with triggering anything — it's display. A shop's admin UI or a customer's order receipt typically wants to show *how* a payment was actually made: the card brand, its last digits, an authorization code, or whatever else the processor reported. That detail lives on the successful attempt, as a set of **labels**.

A transaction can be charged more than once — a retry after a decline, for example — so it may have several attempts, of which at most one succeeded.

```php
// Every attempt, in the order the API returned them.
$attempts = $charge->findAllAttemptsByTransaction($spaceId, $transactionId);
```

Most consumers want only the successful one, to pull its display labels:

```php
$chargeAttempt = $charge->findSuccessfulAttemptByTransaction($spaceId, $transactionId);

$cardBrand   = $chargeAttempt?->getLabel(1001)?->content;      // one label by descriptor ID
$brandLabels = $chargeAttempt?->getLabelsByGroup('4') ?? [];   // every label in a group
```

👉 **See this in action:** [7_fetch_charge_attempt_labels.php](../examples/2-Checkout-Flow/7_fetch_charge_attempt_labels.php)

`findSuccessfulAttemptByTransaction()` is a filter over `findAllAttemptsByTransaction()`: it reads every attempt and returns the first one `ChargeAttempt::isSuccessful()` accepts. Use `isSuccessful()` rather than comparing `$attempt->state` yourself — it is where the rule lives, and it reads the same on both API versions.

`null` is a normal outcome, not an error: a transaction that is still pending, was voided, or whose charge failed simply has no successful attempt. An empty list from `findAllAttemptsByTransaction()` likewise just means the transaction has never been charged.

### Labels and Their Groups

`Label::$groupId` identifies the descriptor group and is populated the same way on every API version, so filter on it if your plugin has to run against both.

`Label::$groupName` is a [`LocalizedString`](../../src/Localization/LocalizedString.php) for display, and is populated only where the API returns the group inline with the attempt — on `WebServiceAPIV1` it is always `null`, because that API reports the group as a bare ID. Treat it as an optional extra:

```php
$label = $chargeAttempt->getLabel(1001);
$groupLabel = $label?->groupName?->localize('en-US') ?? 'Payment details';
```

Descriptor IDs and group IDs are defined by the WeArePlanet Portal, not by PluginCore. Resolve them into human-readable names through [Global Data](../1-Getting-Started/GlobalData.md), or look them up in the WeArePlanet Portal's label descriptor list.

## Errors

Every method throws `Charge\Exception\ChargeException` when the operation fails — an unreachable API, a rejected request, or a transaction in a state that does not permit charging. One exception type covers them all: they share a gateway, a failure mode, and a caller response. Like every other PluginCore exception, it exposes `isRetryable()` — see [Error Handling](../1-Getting-Started/ErrorHandling.md).
