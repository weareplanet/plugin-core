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
*   **[Read Checkout Docs](docs/2-Checkout-Flow/Checkout.md)**

### 2. Webhook Processor
The engine for handling asynchronous events from the WeArePlanet Portal. It's built for scale and high concurrency.
*   **[Read Webhook Processor Docs](docs/4-Background-Tasks/Webhook-Processor.md)**

### 3. Webhook Management
Tools for programmatically managing webhooks in the WeArePlanet Portal, including URL creation and Listener setup.
*   **[Read Webhook Management Docs](docs/4-Background-Tasks/Webhook-Management.md)**

### 4. Transaction Completion (Capture & Void)
Manage the final stages of the transaction lifecycle. Finalize payments (Capture) or cancel them (Void) with dedicated service handlers.
*   **[Read Completion Docs](docs/3-Post-Payment/Completion.md)**

### 5. Recurring Payments
Enables Merchant Initiated Transactions (MIT) for seamless subscription renewals and unscheduled subsequent charges using saved tokens.
*   **[Read Recurring Docs](docs/4-Background-Tasks/Recurring.md)**

### 6. Refund Management
Support for full and partial refunds. Includes precise line-item logic and validation to prevent over-refunding.
*   **[Read Refund Docs](docs/3-Post-Payment/Refund.md)**

### 7. Document Management
Retrieve official PDF documents (Invoices, Packing Slips, Credit Notes) directly from the WeArePlanet Portal for the merchants.
*   **[Read Document Docs](docs/3-Post-Payment/Document.md)**

### 8. Payment Method Service
A centralized service to fetch available payment method configurations from the WeArePlanet Portal, ensuring the shop systems have an up-to-date view of available payment methods.
*   **[Read Payment Method Docs](docs/2-Checkout-Flow/PaymentMethod.md)**

### 9. Manual Task Tracking
Check how many manual tasks (e.g. a manual risk review) are outstanding for a space, so the shop can surface a reminder to the merchant before a transaction can proceed.
*   **[Read Manual Task Docs](docs/4-Background-Tasks/ManualTask.md)**

### 10. Token Management
Saved payment credentials for recurring/Merchant Initiated Transactions, with explicit control over how and when tokenization is applied at checkout.
*   **[Read Token Docs](docs/2-Checkout-Flow/Token.md)**

### 11. Charge
Charge a transaction through its charge flow, and read the resulting charge attempts along with the labels the payment processor reported, such as the card brand or the acquirer reference.
*   **[Read Charge Docs](docs/2-Checkout-Flow/Charge.md)**

### 12. Global Data
Look up the currencies, languages, payment connectors, and label descriptors the WeArePlanet Portal supports — global data, not tied to any space — through the single `GlobalDataService` facade.
*   **[Read Global Data Docs](docs/1-Getting-Started/GlobalData.md)**

### 13. Plugin Identification
Name your shop system and plugin version on every API call, so the WeArePlanet Portal can trace a request back to the installation that made it. Optional, and configured once on the `SdkProvider`.
*   **[Read Plugin Identification Docs](docs/1-Getting-Started/PluginIdentification.md)**

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
[Apache-2.0](LICENSE.txt)
