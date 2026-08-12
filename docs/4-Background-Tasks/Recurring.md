# Recurring Payments

The **Recurring Payment** functionality enables Merchant Initiated Transactions (MIT). This allows charging an existing transaction (representing a saved payment token) immediately without requiring direct user interaction in the browser.

This is commonly used for subscription renewals or unscheduled subsequent charges where the cardholder is not present.

## Core Concepts

**1. Tokenization at Checkout (Prerequisite)**
The original transaction **must** be created with `tokenizationMode = FORCE_CREATION`. This tells the API to automatically generate a token with the customer's stored payment credentials when the payment completes. Without this, there is no token to charge against.

**2. Process via Token**
The recurring payment process creates a new transaction linked to the existing token, then charges it using `processWithToken`. This leverages the stored payment credentials from the token.

**3. The Recurring Gateway**
The logic is encapsulated in the `RecurringTransactionGatewayInterface`. This interface exposes a specific method for processing recurring charges: `processRecurringPayment`.

> [!IMPORTANT]
> Recurring payments will **fail** if the original transaction was not created with tokenization enabled. The error message will indicate that no token exists.

## Integration Guide

### Step 0: Enable Tokenization at Checkout

When creating the original transaction, set `tokenizationMode`:

```php
use WeArePlanet\PluginCore\Token\TokenizationMode as TokenizationModeEnum;

$context = new TransactionContext();
// ... set other fields ...
$context->tokenizationMode = TokenizationModeEnum::FORCE_CREATION;
```

The token this produces — reading its active version, or deleting it when the customer withdraws consent — is covered in [Token](../2-Checkout-Flow/Token.md).

### Step 1: Configure the Service

Use `RecurringTransactionService`.

```php
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\RecurringTransactionGateway;

$recurringGateway = new RecurringTransactionGateway($sdkProvider, $logger);
$recurringService = new RecurringTransactionService($transactionService, $recurringGateway, $logger);
```

### Step 2: Execute Recurring Payment

The recurring payment is triggered using the original transaction ID and the space ID.

```php
$newTransaction = $recurringService->processRecurringPayment($spaceId, $originalTransactionId);

// A recurring charge can resolve to FAILED without throwing — inspect the state.
echo $newTransaction->state->value;
```

👉 **See this in action:** [recurring.php](../examples/4-Background-Tasks/recurring.php)

When the WeArePlanet Portal needs the merchant to act before a transaction can proceed — a risk review on a renewal, for instance — that work appears as a manual task in the space. See [Manual Task](ManualTask.md) for counting what is outstanding so your plugin can prompt them.

## Flow Diagram

```mermaid
sequenceDiagram
    participant Scheduler
    participant PluginCore
    participant WeArePlanetAPI

    Scheduler->>PluginCore: processRecurringPayment(spaceId, originalTransactionId)
    PluginCore->>WeArePlanetAPI: readTransaction(originalId, expand: [token])
    WeArePlanetAPI-->>PluginCore: Original Transaction (with Token)
    
    alt No Token Found
        PluginCore-->>Scheduler: Error: tokenizationMode was not enabled at checkout
    end

    PluginCore->>WeArePlanetAPI: createTransaction(context with token)
    WeArePlanetAPI-->>PluginCore: New Transaction

    PluginCore->>WeArePlanetAPI: processWithToken(newTransactionId)
    WeArePlanetAPI-->>PluginCore: Charge (SUCCESSFUL)
    PluginCore-->>Scheduler: Domain Transaction (FULFILL)
```

## Running the Example

A working example is provided in the `example` directory.

> [!IMPORTANT]
> The recurring payment example requires a transaction that was created **with tokenization enabled** and has already been paid. You must run the updated Checkout examples first to create such a transaction.

1. **Start Checkout**: Run `docs/examples/2-Checkout-Flow/3_start_checkout.php` (it includes `FORCE_CREATION` tokenization).
2. **Confirm & Pay**: Run one of the `docs/examples/2-Checkout-Flow/5_confirm_*.php` scripts matching your integration mode (e.g. `5_confirm_manual.php`) and follow the link to pay.
3. **Trigger Recurring**: Run `docs/examples/4-Background-Tasks/recurring.php`.
    * This script automatically detects the active session from the Checkout example.
    * Alternatively, you can pass the transaction ID manually:

      ```bash
      php recurring.php <transaction_id>
      ```
