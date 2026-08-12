# Chapter 2: Checkout Flow

The customer-facing half of the integration: showing a customer their payment options, creating a transaction, and getting it authorized.

By the end of this chapter you can take a cart, turn it into a transaction, render a payment form in whichever integration mode you have chosen, and read back what actually happened.

## Contents

### Preparation

- **[Payment Method](PaymentMethod.md)** — Retrieving the payment methods a merchant has configured, and synchronizing them into your own storage.

### Execution

- **[Checkout](Checkout.md)** — The stateless, resilient transaction engine that lets customers navigate back and forth without creating duplicates.
- **[Checkout Architecture](Checkout-ARCHITECTURE.md)** — The design behind the engine: the ports-and-adapters split, the upsert flow, and why integration mode is abstracted away.
- **[Charge](Charge.md)** — Applying a transaction's charge flow, and reading back the charge attempt with the labels the payment processor reported.

### Special Cases

- **[Token](Token.md)** — Saved payment credentials, their versions, and the tokenization modes that must be set at checkout for a token to exist at all.
- **[Delivery Indication](DeliveryIndication.md)** — The post-capture review request that holds an order until the merchant decides it is safe to ship.

## Examples

Runnable scripts for this chapter live in [`examples/2-Checkout-Flow/`](../examples/2-Checkout-Flow/), numbered as a single walkthrough from listing payment methods through to inspecting a completed transaction. Start at [`1_list_payment_methods.php`](../examples/2-Checkout-Flow/1_list_payment_methods.php) and follow the numbers.

---

[← Chapter 1: Getting Started](../1-Getting-Started/README.md) · [Documentation index](../README.md) · [Chapter 3: Post-Payment →](../3-Post-Payment/README.md)
