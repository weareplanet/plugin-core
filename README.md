# WeArePlanet Plugin Core Library

**The canonical, framework-agnostic business logic engine for WeArePlanet payment integrations.**

This library abstracts the complexity of the WeArePlanet SDK and provides a standardized, robust implementation of payment flows. It is designed to be used as a core dependency by platform-specific plugins (Magento, WooCommerce, Shopware, etc.), decoupling **business logic** from **platform infrastructure**.

---

## Core Philosophy

The goal of this project is to centralize all payment business logic into a single, reusable library, decoupling it from the specific constraints of platforms like Magento or WooCommerce.

Instead of duplicating complex logic across different shop systems, `plugin-core` implements the payment workflows once, using pure PHP. This shifts the role of the shop-specific plugin:

* **Plugin Core:** Implements the business logic, manages state machines, and handles all API interactions via the SDK.
* **Shop Plugin:** Acts as an **adapter**. It interchanges data between the shop and the Core, handles database persistence, manages configuration, and integrates into the shop's frontend/backend events.

### Key Architectural Benefits
* **Pure PHP:** Framework-agnostic code that runs anywhere PHP runs.
* **Minimal Dependencies:** Depends only on the official `weareplanet/php-sdk`, making it lightweight and easy to port to any environment.
* **Type Safety:** Written with strict typing to catch errors early.
* **Testability:** Designed for 100% unit test coverage with isolated components.
* **PSR Standards:** Fully compliant with PSR-3 (Logging) and other standard interfaces.
* **Contract-Driven:** Clear Interfaces and Abstract Base Classes guide developers to implement the necessary platform-specific adapters correctly.
---

## Key Features

The library is divided into major functional components, each designed for robustness and ease of integration.

### 1. Checkout Engine
The core of the payment flow. Handles transaction creation and management with a sophisticated "upsert" strategy, ensuring seamless navigation without duplicate charges.
*   **[Read Checkout Docs](docs/Checkout/README.md)**

### 2. Webhook Processor
The engine for handling asynchronous events from the WeArePlanet Portal. It's built for scale and high concurrency.
*   **[Read Webhook Processor Docs](docs/Webhook/Processor/README.md)**

### 3. Webhook Management
Tools for programmatically managing webhooks in the WeArePlanet Portal, including URL creation and Listener setup.
*   **[Read Webhook Management Docs](docs/Webhook/Management/README.md)**

### 4. Transaction Completion (Capture & Void)
Manage the final stages of the transaction lifecycle. Finalize payments (Capture) or cancel them (Void) with dedicated service handlers.
*   **[Read Completion Docs](docs/Completion/README.md)**

### 5. Recurring Payments
Enables Merchant Initiated Transactions (MIT) for seamless subscription renewals and unscheduled subsequent charges using saved tokens.
*   **[Read Recurring Docs](docs/Recurring/README.md)**

### 6. Refund Management
Support for full and partial refunds. Includes precise line-item logic and validation to prevent over-refunding.
*   **[Read Refund Docs](docs/Refund/README.md)**

### 7. Document Management
Retrieve official PDF documents (Invoices, Packing Slips, Credit Notes) directly from the WeArePlanet Portal for the merchants.
*   **[Read Document Docs](docs/Document/README.md)**

### 8. Payment Method Service
A centralized service to fetch available payment method configurations from the WeArePlanet Portal, ensuring the shop systems have an up-to-date view of available payment methods.
*   **[Read Payment Method Docs](docs/PaymentMethod/README.md)**

### 9. Manual Task Tracking
Check how many manual tasks (e.g. a manual risk review) are outstanding for a space, so the shop can surface a reminder to the merchant before a transaction can proceed.
*   **[Read Manual Task Docs](docs/ManualTask/README.md)**

### 10. Token Management
Saved payment credentials for recurring/Merchant Initiated Transactions, with explicit control over how and when tokenization is applied at checkout.
*   **[Read Token Docs](docs/Token/README.md)**

---

## Documentation & Examples

For detailed implementation guides and runnable examples for every module, see the [documentation index](docs/README.md).

---

## Installation

```bash
composer require weareplanet/plugin-core
```

---

## Unit Tests
You can run the test suite to verify the library's behavior.

```bash
composer test
```

## License
[License Information Here]
