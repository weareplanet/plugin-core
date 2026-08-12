# Chapter 4: Background Tasks

Everything that runs with no customer in the browser: reacting to events the WeArePlanet Portal sends you, charging saved tokens on a schedule, and surfacing work the merchant still owes.

This is the chapter that decides whether an integration is merely correct or actually reliable in production — most of it is about surviving concurrency, retries, and out-of-order delivery.

## Contents

Webhooks come in two halves that solve different problems — most plugins need both.

### Webhook Processing

The core logic you implement in your shop plugin to handle state changes, locking, and business logic (Commands).

- **[Webhook Processor](Webhook-Processor.md)** — The integration guide: listeners, commands, and the lifecycle handler.
- **[Webhook Architecture](Webhook-Processor-ARCHITECTURE.md)** — The catch-up loop, two-stage locking, atomic persistence, and the state-precedence rules that keep concurrent deliveries safe.
- [Simulation example](../examples/4-Background-Tasks/webhook-processor/) — a full reference implementation you can read end to end.

### Webhook Management

The tools for programmatically managing webhooks in the WeArePlanet Portal: URL creation, listener setup, and uninstallation.

- **[Webhook Management](Webhook-Management.md)** — Creating and synchronizing webhook subscriptions.
- [Lifecycle demo (CLI)](../examples/4-Background-Tasks/manage_webhooks.php) — installs, updates and removes a subscription.

### Scheduled & Manual Tasks

- **[Recurring](Recurring.md)** — Merchant Initiated Transactions: charging a saved token with no cardholder present, for subscription renewals and follow-up charges.
- **[Manual Task](ManualTask.md)** — Counting the actions a merchant still has to complete in the WeArePlanet Portal, so your plugin can surface a badge or reminder.

## Examples

All runnable scripts for this chapter live in [`examples/4-Background-Tasks/`](../examples/4-Background-Tasks/).

---

[← Chapter 3: Post-Payment](../3-Post-Payment/README.md) · [Documentation index](../README.md)
