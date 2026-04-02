# Webhook Architecture & Best Practices

This document details the architecture of the `plugin-core` webhook system. It is designed to handle **high-concurrency** environments, **out-of-order** delivery, and **distributed race conditions** inherent in payment gateway integrations.

---

## 1. The Core Philosophy: "Catch-Up" Logic

The fundamental problem with **webhook invocations** is that they are asynchronous and unreliable. A shop might receive a `FULFILL` invocation before an `AUTHORIZED` invocation due to network latency or retry logic.

`plugin-core` solves this using a **State Machine "Catch-Up" loop**:

1. **Fetch Remote State:** It gets the authoritative state from the WeArePlanet API (e.g., `FULFILL`).
2. **Fetch Local State:** It looks up the *last processed state* locally (e.g., `CREATE`).
3. **Calculate Path:** It determines the transition path: `[PENDING -> AUTHORIZED -> FULFILL]`.
4. **Execute Loop:** It executes the commands for **every step** in that path, in order.

**Why?** This guarantees that the shop's data always reaches the correct final state and triggers all necessary side effects (emails, invoices), even if intermediate invocations were lost or arrived out of order.

> **Reference:** For a visual representation of the transaction states, see the [official WeArePlanet Transaction Process documentation](https://paymentshub.weareplanet.com/en-us/doc/payment/transaction-process#_transaction_states).

---

## 2. Concurrency Control (Locking)

When high-volume transactions occur, multiple invocations (e.g., `Authorized`, `Invoice`, `Fulfill`) can arrive at the exact same millisecond. Without locking, this leads to data corruption, duplicate orders, or database deadlocks.

To prevent this, `plugin-core` orchestrates a strict locking lifecycle via the `WebhookLifecycleHandler`.

### Stage 1: The Entity Lock (Deduplication)

* **Target:** The specific webhook entity (e.g., `Transaction_123`).
* **Purpose:** Prevents two processes from handling the exact same event at once.
* **Behavior:** If Process A is handling `Transaction_123`, Process B waits. When Process B finally runs, it checks the database, sees `Transaction_123` is already up-to-date, and skips.

---

### Stage 2: The Resource Lock (Shared Resources)

* **Target:** The shop resource being modified (e.g., `Order_999`).
* **Purpose:** Prevents different *types* of webhook invocations (e.g., `Transaction` vs. `DeliveryIndication`) from modifying the same Order simultaneously.
* **Behavior:** Even if they are different entities, if they update the same Order, they must wait in line.

> **Implementation Example:** See [example/src/MyExampleLifecycleHandler.php](example/src/MyExampleLifecycleHandler.php) for a reference implementation of `getLockableResources` used to define these locks.

---

## 3. Persistence & Transactions

The `WebhookProcessor` enforces an **Atomic Transaction** per step in the catch-up loop.

1. **Pre-Process:** Acquires locks, re-checks the state (to skip duplicates), and starts a database transaction.
2. **Execute:** Runs the business logic Command.
3. **Post-Process:** Updates the `webhook_progress` table, commits the transaction, and releases locks.

**Critical Rule:** The `webhook_progress` table is the source of truth for `plugin-core`. It must be updated in the same atomic transaction as the business logic.

---

## 4. Data Integrity & State Precedence

Locking ensures commands run one at a time, but it does not guarantee the *order* in which they run. A `Delivery Indication` (forcing Manual Review) might arrive milliseconds *after* a `Capture` command (forcing Processing).

To prevent valid data from being overwritten, Commands must implement **State Precedence**.

### The "Safe Update" Pattern

A Command must never blindly overwrite the status of a resource. It must check the current state of the resource (loaded freshly from the database) and verify that the new state is "compatible" with the existing one.

**The Priority Hierarchy (Example):**

1. **Final States** (Closed, Canceled) - *Highest Priority (Immutable)*
2. **Review States** (Manual Check Required) - *High Priority*
3. **Process States** (Processing, Authorized) - *Standard Priority*

**The Rule:**
A Standard Priority command (like `Authorized`) **must not** overwrite a High Priority state (like `Manual Check`).

* **Scenario:** If an Order is in `Manual Check`, and the `Authorized` command runs:
  * **Incorrect:** Overwrite status to `Processing`. (The merchant loses the review flag).
  * **Correct:** Record the authorization transaction, but **skip** the status update.

* **Implementation Example**: See [example/src/Transaction/TransactionListener.php](example/src/Transaction/TransactionListener.php) for a reference implementation of this safety check.

---

## 5. Implementation Checklist

To implement `plugin-core` in a new shop system:

* [ ] **Database:** Create a `webhook_progress` table with columns `(entity_id, entity_type, last_processed_state)`.
* [ ] **Lifecycle Handler:** Create a base handler extending `DefaultWebhookLifecycleHandler`.
  * [ ] Implement `getLastProcessedState`.
  * [ ] Implement `getLockableResources` (returning both Entity and Resource lock IDs).
  * [ ] Implement `doAcquireLock` / `doReleaseLock`.
  * [ ] Implement `preProcess` (Start Transaction) / `postProcess` (Commit & Update Table).
* [ ] **Commands:** Write simple commands.
  * [ ] Use traits for data loading.
  * [ ] Use the **Safe Update Pattern** (Fresh Load + State Precedence) to prevent overwriting critical statuses.
* [ ] **Wiring:** Configure the `WebhookProcessor` with your handler.
