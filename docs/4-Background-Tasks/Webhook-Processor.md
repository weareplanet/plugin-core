# Webhook Processor

The **Webhook Processor** is the component that turns a notification from the WeArePlanet Portal into the right changes in your shop — creating an invoice, marking an order paid, cancelling it — exactly once, in the right order, even when notifications arrive late, twice, or out of sequence.

It is the most involved part of PluginCore, because it is the only part that runs without a customer waiting, against an unreliable network, possibly several times at once for the same order.

It is not limited to payments. Any entity the WeArePlanet Portal raises webhooks for goes through the same machinery — a `Refund` changing state, a `DeliveryIndication` awaiting review, a `ManualTask`, a `Token`, a `TransactionCompletion`, and more. The examples below use `Transaction` because it is the most familiar, but nothing in the processor is specific to it: you register a `Listener` per entity type and state, and the catch-up loop treats them all alike.

## What Problem It Solves

An entity's lifecycle — a payment, a refund, a delivery review — happens on the WeArePlanet Portal's side, not in your shop. The WeArePlanet Portal tells you about it by calling a URL you registered (see [Webhook Management](Webhook-Management.md) for registering them). That sounds simple, but the delivery has properties a naive handler gets wrong:

- **Notifications can arrive out of order.** `FULFILL` may land before `AUTHORIZED` because of a network retry. A handler that just applies "what the payload says" would skip creating the invoice that `AUTHORIZED` was supposed to trigger.
- **Notifications can arrive more than once.** The same state may be delivered repeatedly. Applying it twice means two invoices, or two stock deductions.
- **Several can arrive at the same instant.** Two workers processing the same order concurrently can interleave and corrupt it.
- **The payload arrives on a public URL.** Anyone can POST to it, so a state it claims is only worth acting on once it has been proven genuine.

The processor is what makes all four survivable, so your own code — the `Command` you write — can be plain business logic that assumes it runs once, in order, on fresh data.

## The Key Idea: State-Driven, Not Event-Driven

The one thing worth internalizing before reading the rest: **the processor never acts on a state it has not verified, and never on the payload alone.**

Resolving that state is the job of `StateFetcherInterface`. The bundled `DefaultStateFetcher` takes one of two routes, depending on what arrived:

- **Signed payload** (an `x-signature` header is present): the raw body is checked against the signature. If it verifies, the state is read straight from the payload — genuine, and **no extra API call**. If the signature fails, or a signed payload carries no `state`, the request is rejected outright.
- **Unsigned payload**: there is nothing to trust, so the state is read from the API instead, resolving the right service from the entity's technical name and retrying on transient network failures.

Either way the processor ends up with a state it can rely on. It then compares that against the last state your shop recorded for that entity and works out which steps are missing — which is what makes the delivery problems above disappear: an out-of-order or duplicated notification is just another prompt to re-check the truth, and a forged payload cannot get past either route.

A plugin can replace `StateFetcherInterface` entirely if its platform resolves state some other way.

## What Happens When a Webhook Arrives

`WebhookProcessor::process($request)` runs the same sequence every time:

1. **Validate the payload.** Reject it unless it carries `listenerEntityTechnicalName`, `entityId` and `spaceId`. A malformed request is logged as a warning, not an error — it usually signals a configuration mismatch, not a fault.
2. **Resolve the verified state** via `StateFetcherInterface` — from the signed payload when the signature checks out, otherwise by reading it from the API.
3. **Read the last processed state** your shop recorded, via your lifecycle handler.
4. **Compute the transition path** between the two — the ordered list of states that still need to be applied.
5. **Decide whether there is anything to do.** An impossible or backwards transition (a stale delivery) is ignored; an empty path means the entity is already up to date, so a duplicate is ignored too.
6. **Walk the path, one state at a time.** For each step the processor calls your lifecycle handler's `preProcess()`, looks up the `Listener` registered for that state, runs the `Command` it returns, then calls `postProcess()` to record the new state. Locking happens in those hooks; anything else they need to set up and tear down — a database transaction in your shop, say — is yours to add there. States with no registered listener are still recorded, since they exist for tracking.
7. **On failure, unwind and let the WeArePlanet Portal retry.** The handler's `onFailure()` hook runs — releasing locks, and rolling back the database transaction if it opened one — and a `CommandException` propagates so your controller can answer 5xx, which is the WeArePlanet Portal's cue to redeliver.

Step 6 is the "catch-up loop", and it is the reason a late `FULFILL` still produces the invoice that `AUTHORIZED` should have: the missing step is replayed before the current one.

> [!IMPORTANT]
> Because a step can be replayed and a delivery retried, a `Command` must be safe to run against an order that another process may have touched. Always reload the record, and never blindly overwrite a status that outranks the one you are applying. That rule, the locking stages behind step 6, and the retry semantics of step 7 are covered in the **[Architecture Overview](Webhook-Processor-ARCHITECTURE.md)** — read it before writing your first `Command`.

## What PluginCore Provides, and What You Build

PluginCore owns the ordering, deduplication, locking and retry machinery. You supply the parts only your platform knows:

| You implement | Because only your shop knows |
|---|---|
| `SettingsProviderInterface` | Where credentials and the Space ID live. |
| `WebhookLifecycleHandler` | How to lock, where the last processed state is stored, and any per-step setup/teardown your platform needs (a transaction, for instance). |
| `Command` | What "mark this order paid" actually means in your data model. |
| `Listener` | Which command answers which state. |
| `StateMapperInterface` *(optional)* | Your own state vocabulary, if it differs from PluginCore's. |
| `LoggerInterface` | Where logs go. |

Everything else — the catch-up loop, the transition validation, the duplicate and race detection, the failure flow — is PluginCore's side of the contract.

---

## Core Concepts

The components involved, in the order they come up:

* **`SettingsProviderInterface` / `DefaultSettingsProvider`**: Provides essential configuration (Space ID, User ID, etc.) to `plugin-core`. Plugins **extend `DefaultSettingsProvider`** to provide these values.
* **`Settings`**: An object in `plugin-core` that fetches and validates configuration.
* **`SdkProvider`**: A service in `plugin-core` that uses `Settings` to create a configured WeArePlanet SDK `ApiClient`.
* **`StateFetcherInterface`**: An interface for resolving the webhook's verified current state (**`remoteState`**). `plugin-core` provides a `DefaultStateFetcher`, which reads it from a signature-verified payload where possible and falls back to an API read otherwise.
* **`WebhookLifecycleHandler`**: The bridge between the core engine and your shop's infrastructure. It handles locking, transactions, and tracking progress.
* **`StateMapperInterface`**: An **optional** interface a plugin can implement to "translate" between `plugin-core`'s standard states (e.g., `COMPLETED`) and the application's own custom state names (e.g., `wc-processing`).
* **`WebhookProcessor`**: The main service that orchestrates the entire process, calling the `WebhookLifecycleHandler` hooks at the appropriate times.
* **`Listener`**: Provides the correct `Command` for a specific webhook event.
* **`Command`**: Contains the **pure business logic** (e.g., creating an invoice). It receives the full event details via a `WebhookContext` object.
* **`TransactionActionResolver` / `LifecycleAction`**: Translates a `Transaction\State` into the coarse-grained shop action it implies (`AUTHORIZE`, `FULFILL`, `CANCEL_ORDER`, `IGNORE`), so your `Listener` never has to interpret raw gateway states itself. See [Mapping States to Actions](#mapping-states-to-actions-transactionactionresolver) below.
* **`LoggerInterface`**: The plugin **must provide** a PSR-3 compatible logger implementation (or adapter) so the core can log debug information and errors.

---

## Implementation Steps

The plugin developer's responsibility is to create the concrete implementations for these components.

### Step 1: Implement the Settings Provider

The developer must create a class that **extends `DefaultSettingsProvider`** to provide the necessary API credentials and Space ID.

### Step 2: Implement the Webhook Lifecycle Handler

The developer must create a class that **extends `DefaultWebhookLifecycleHandler`**.

Instead of writing complex locking logic manually, you simply tell the handler **what** to lock and **how** to lock it:

* **`getLastProcessedState()`**: Look up the last processed state from your `webhook_progress` database table.
* **`getLockableResources()`**: Return a list of unique IDs to lock (e.g., the Webhook Entity ID and the Shop Order ID).
* **`doAcquireLock()` / `doReleaseLock()`**: Implement the actual calls to your shop's locking system (e.g., Redis lock, DB lock).
* **`preProcess()` / `postProcess()`**: (Optional) Override these *only* if you need to wrap the execution in a Database Transaction. **Always call parent** to ensure locking runs.

### Step 3: Create a State Mapper (If Needed)

If the application's state names differ from `plugin-core`'s, create a class that implements `StateMapperInterface`.

### Step 4: Define the Action (The `Command`)

A `Command` contains the **pure business logic**.

* **It SHOULD:** Modify shop resources (Orders, Invoices) based on the webhook data.
* **It SHOULD NOT:** Manage the `webhook_progress` state or handle low-level locking (this is done by the Lifecycle Handler).

**Important:** Commands must follow the **"Safe Update"** pattern. Always reload the resource (Order) from the database to ensure it isn't stale, and check for protected states (e.g., "Payment Review") before overwriting status. See the **[Architecture Overview](Webhook-Processor-ARCHITECTURE.md)** document for more information.

**Transient failures:** If the command (or your `preProcess()` lock acquisition) hits a temporary, self-healing condition — e.g. a lock contention timeout under concurrent deliveries — throw a `TransientWebhookException` (`WeArePlanet\PluginCore\Webhook\Exception`). The core performs the normal rollback and 5xx-retry flow but logs the event at `info` severity instead of `error`, keeping the logs free of false alarms. See **[Failure Handling & Retries](Webhook-Processor-ARCHITECTURE.md)** for details.

### Step 5: Create the Rule (The `Listener`)

A `Listener` connects a webhook event to a `Command`. Its `getCommand()` method receives the `WebhookContext` and creates the `Command`.

#### Mapping States to Actions: `TransactionActionResolver`

Do **not** write custom `if`/`else` (or `match`) logic in your `Listener` to guess what a `Transaction\State` means for your shop — e.g. deciding for yourself whether `AUTHORIZED` is "close enough" to `FULFILL` to generate an invoice. That interpretation is a business decision `plugin-core` already makes for you. Delegate it to `TransactionActionResolver::resolve()`, which translates any `Transaction\State` into one of four `LifecycleAction` cases:

| `LifecycleAction` | Meaning | Typical shop action |
|---|---|---|
| `AUTHORIZE` | Payment is secured (state `AUTHORIZED`). | Send the order confirmation email; lock the cart so it can't be re-submitted. |
| `FULFILL` | Funds are captured and ready (states `COMPLETED`, `FULFILL`). | Generate the invoice; flag the order for shipping. |
| `CANCEL_ORDER` | Payment failed or was voided (states `FAILED`, `VOIDED`, `DECLINE`). | Cancel the shop order. |
| `IGNORE` | An intermediate or tracking-only state (`CREATE`, `PENDING`, `CONFIRMED`, `PROCESSING`). | Safely take no action — the catch-up loop still records it. |

A `Listener` typically injects the resolver into its constructor, then `match`es on the `LifecycleAction` returned by `resolve()` to pick the right `Command` — this keeps the business meaning of a gateway state defined in exactly one place (`TransactionActionResolver`) instead of scattered across every plugin's own conditional logic.

### Step 6: Register The `Listener`

In the application's initialization logic (e.g., Magento's `di.xml` via a factory), the `Listener` is added to a central `WebhookListenerRegistry`.

### Step 7: Wire Everything Together

Using the platform's DI system (e.g., `di.xml`):

* Set a preference for `SettingsProviderInterface` (Step 1).
* Set a preference for `WebhookLifecycleHandler` (Step 2).
* Set a preference for `StateFetcherInterface`.
* Configure the `WebhookProcessor` to inject these components, along with the `ListenerRegistry` and `Logger`.
* The `WebhookProcessor` is then injected into the controller and its `process()` method is called.

## Usage Example

See the [example](../examples/4-Background-Tasks/webhook-processor) directory for a simulated webhook processing flow:

1. Initialize services.
2. Register listeners for different entities and states.
3. Simulate incoming webhook requests and observe the processing lifecycle (locking, catch-up logic, command execution).
