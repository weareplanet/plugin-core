# Documentation Index

This directory contains the documentation for the various modules of the WeArePlanet Plugin Core library.

## Running the Examples

Every runnable script under a module's `example/` directory shares the same bootstrap (`docs/examples/Common/bootstrap.php`), which requires a WeArePlanet Space ID, User ID, and API Secret, set as environment variables:

```bash
export PLUGINCORE_DEMO_SPACE_ID=12345
export PLUGINCORE_DEMO_USER_ID=98765
export PLUGINCORE_DEMO_API_SECRET='your-api-secret-key'
```

```fish
set -x PLUGINCORE_DEMO_SPACE_ID 12345
set -x PLUGINCORE_DEMO_USER_ID 98765
set -x PLUGINCORE_DEMO_API_SECRET 'your-api-secret-key'
```

Without these, any example script exits immediately with an "ERROR: Missing Credentials." error. Set them once per terminal session, then run any example from its own directory.

## Modules

- [Error Handling](./ErrorHandling.md): Retryable exceptions and state capability predicates.
- [Checkout](./Checkout/): Handling the initial payment process and transaction creation.
- [Completion](./Completion/): Finalizing authorized transactions (Capture and Void).
- [Document](./Document/): Retrieving official PDF documents (Invoices, Packing Slips, Credit Notes).
- [Manual Task](./ManualTask/): Checking for outstanding manual tasks in a space.
- [Payment Method](./PaymentMethod/): Retrieving and synchronizing WeArePlanet Portal configurations for available payment methods.
- [Recurring](./Recurring/): Implementing tokenized Merchant Initiated Transactions (MIT).
- [Refund](./Refund/): Managing full and partial refunds.
- [Token](./Token/): Saved payment credentials and tokenization modes for recurring charges.
- [Webhook](./Webhook/): Guide for handling asynchronous WeArePlanet Portal events (Processing and Management).
