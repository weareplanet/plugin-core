# Checkout Architecture

This document outlines the architectural decisions behind the Checkout Engine in `PluginCore`.

## High-Level Design

The Checkout Engine follows a **Hexagonal (Ports & Adapters)** style architecture. The core domain logic is decoupled from the external WeArePlanet SDK and the host application's storage mechanism.

### Key Components

1.  **TransactionService (Domain Orchestrator):**
    * **Role:** The main entry point.
    * **Responsibility:** Enforces business rules (e.g., Line Item Consistency), manages the `Upsert` flow, and delegates API calls.
    * **Dependencies:** It depends only on interfaces (`TransactionGatewayInterface`, `TransactionPersistenceInterface`), making it easy to test.

2.  **TransactionGateway (Infrastructure / ACL):**
    * **Role:** The Anti-Corruption Layer.
    * **Responsibility:** Translates internal domain objects (`TransactionContext`) into SDK-specific models (`TransactionCreate`) and communicates with the WeArePlanet API.
    * **Benefit:** This isolates the core logic from specific SDK versions. To support SDK v2, we simply swap the Gateway implementation.

3.  **TransactionPersistenceInterface (Port):**
    * **Role:** A contract the host application must implement.
    * **Responsibility:** Handles the storage of the WeArePlanet Transaction ID (e.g., in `$_SESSION` or a database table).
    * **Benefit:** `PluginCore` remains stateless and framework-agnostic. It tells the host *when* to save an ID, but not *how*.

---

## Design Decisions

### 1. The "Upsert" Flow (Optimistic Update)
Instead of a "Check-Then-Act" flow (which is prone to race conditions), the engine uses an **Optimistic** approach:
1.  **Try Update:** If a Transaction ID exists in the context, attempt to update it immediately.
2.  **Fallback to Create:** If the update fails (e.g., ID is 404 or expired), catch the error and create a fresh transaction.
3.  **Enforce Persistence:** If a new transaction was created (Step 2), strictly invoke the persistence strategy to save the new ID.

**Why:** This makes the checkout resilient to browser navigation (Back/Forward buttons) and session timeouts without creating duplicate transactions unnecessarily.

### 2. Context-Driven Data
The `TransactionContext` DTO is the sole source of truth.
**Why:** The Service does not fetch data from the database or the cart. It relies entirely on the Context provided by the controller. This ensures the Service is pure, side-effect free (regarding data fetching), and highly testable.

### 3. Integration Mode Abstraction
The Service exposes a single `getPaymentUrl()` method.
**Why:** The consumer (Controller) does not need to know which specific SDK service (`PaymentPage`, `Iframe`, or `Lightbox`) is being used. This logic is encapsulated within the Gateway based on the `Settings` injection.