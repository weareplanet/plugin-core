# Webhook Integration Guide

This document explains **how to implement** the webhook handling functionality in your shop plugin.

**📘 Architecture & Best Practices**

This guide focuses on the practical **implementation steps**.

To understand the underlying logic—including **concurrency control**, **two-stage locking**, and **race condition prevention**—please read the **[Architecture Overview](ARCHITECTURE.md)** first. It explains *why* these components exist and how they ensure data integrity.

---

## Core Concepts

The system is designed to process incoming webhooks by mapping them to specific actions. This is achieved by working with several components:

* **`SettingsProviderInterface` / `DefaultSettingsProvider`**: Provides essential configuration (Space ID, User ID, etc.) to `plugin-core`. Plugins **extend `DefaultSettingsProvider`** to provide these values.
* **`Settings`**: An object in `plugin-core` that fetches and validates configuration.
* **`SdkProvider`**: A service in `plugin-core` that uses `Settings` to create a configured WeArePlanet SDK `ApiClient`.
* **`StateFetcherInterface`**: An interface for getting the webhook's current (**`remoteState`**). `plugin-core` provides a `DefaultStateFetcher` (which uses the `SdkProvider`).
* **`WebhookLifecycleHandler`**: The bridge between the core engine and your shop's infrastructure. It handles locking, transactions, and tracking progress.
* **`StateMapperInterface`**: An **optional** interface a plugin can implement to "translate" between `plugin-core`'s standard states (e.g., `COMPLETED`) and the application's own custom state names (e.g., `wc-processing`).
* **`WebhookProcessor`**: The main service that orchestrates the entire process, calling the `WebhookLifecycleHandler` hooks at the appropriate times.
* **`Listener`**: Provides the correct `Command` for a specific webhook event.
* **`Command`**: Contains the **pure business logic** (e.g., creating an invoice). It receives the full event details via a `WebhookContext` object.
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

**Important:** Commands must follow the **"Safe Update"** pattern. Always reload the resource (Order) from the database to ensure it isn't stale, and check for protected states (e.g., "Payment Review") before overwriting status. See the **[Architecture Overview](ARCHITECTURE.md)** document for more information.

### Step 5: Create the Rule (The `Listener`)

A `Listener` connects a webhook event to a `Command`. Its `getCommand()` method receives the `WebhookContext` and creates the `Command`.

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

See the [example](example/) directory for a simulated webhook processing flow:

1. Initialize services.
2. Register listeners for different entities and states.
3. Simulate incoming webhook requests and observe the processing lifecycle (locking, catch-up logic, command execution).
