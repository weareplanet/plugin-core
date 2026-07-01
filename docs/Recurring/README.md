## Recurring Payments

The **Recurring Payment** functionality enables Merchant Initiated Transactions (MIT). This allows charging an existing transaction (representing a saved payment token) immediately without requiring direct user interaction in the browser.

This is commonly used for subscription renewals or unscheduled subsequent charges where the cardholder is not present.

### Core Concepts

**1. Process Without User Interaction**
The recurring payment process triggers a charge attempt on a previously successful transaction. It uses the payment information linked to that transaction.

**2. The Recurring Gateway**
The logic is encapsulated in the `RecurringTransactionGatewayInterface`. This interface exposes a specific method for processing recurring charges: `processRecurringPayment`.

**3. Token and Billing Address Requirements**
For a recurring payment to succeed, a valid payment token and billing address must be present on the original transaction. Following the Fail Fast approach, the service throws a domain exception with both a technical message and a localized reason: a `MissingTokenException` (in `WeArePlanet\PluginCore\Token\Exception`) when the token is missing, and a `TransactionException` (in `WeArePlanet\PluginCore\Transaction\Exception`) when the billing address is missing.

### Integration Guide

#### Step 1: Configure the Service

 Use `RecurringTransactionService`.

 ```php
 use WeArePlanet\PluginCore\Transaction\RecurringTransactionService;
 use WeArePlanet\PluginCore\Transaction\TransactionService;
 use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\RecurringTransactionGateway;
 
 // Setup Gateway
 $recurringGateway = new RecurringTransactionGateway($sdkProvider, $logger);
 
 // Instantiate Recurring Service
 $recurringService = new RecurringTransactionService(
     $transactionService,
     $recurringGateway,
     $logger
 );
 ```

#### Step 2: Execute Recurring Payment

 The recurring payment is triggered using the original transaction ID and the space ID. If a token was not created during checkout, you must manually create it using the `TokenService` first.

 ```php
 use WeArePlanet\PluginCore\Token\TokenService;
 use WeArePlanet\PluginCore\Token\Exception\TokenException;
 use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\TokenGateway;

 $tokenGateway = new TokenGateway($sdkProvider, $logger);
 $tokenService = new TokenService($tokenGateway, $logger);

 try {
     // If the original transaction has no token, create one. On failure this now
     // throws a TokenException carrying the gateway's localized rejection reason
     // (it no longer fails silently by returning null).
     $transaction = $transactionService->getTransaction($spaceId, $originalTransactionId);
     if ($transaction->token === null) {
         $transaction->token = $tokenService->createTokenForTransaction($spaceId, $originalTransactionId);
     }

     // Perform the recurring charge
     $newTransaction = $recurringService->processRecurringPayment($spaceId, $originalTransactionId);

     echo "Recurring payment processed! New Transaction ID: " . $newTransaction->id;

     // A recurring charge may resolve to FAILED; the localized failure reason is now preserved.
     if ($newTransaction->failureReason !== null) {
         echo "Failure reason: " . $newTransaction->failureReason->localize('en-US');
     }
 } catch (TokenException $e) {
     $logger->error("Token creation failed: " . ($e->getLocalizedMessage()?->localize('en-US') ?? $e->getMessage()));
 } catch (\Throwable $e) {
     $logger->error("Recurring payment failed: " . $e->getMessage());
 }
 ```

### Flow Diagram

```mermaid
sequenceDiagram
    participant Scheduler
    participant PluginCore
    participant WeArePlanetAPI

    Scheduler->>PluginCore: processRecurringPayment(spaceId, originalTransactionId)
    PluginCore->>WeArePlanetAPI: readTransaction(originalId)
    WeArePlanetAPI-->>PluginCore: Original Transaction

    PluginCore->>WeArePlanetAPI: createTransaction(context)
    WeArePlanetAPI-->>PluginCore: New Transaction

    PluginCore->>WeArePlanetAPI: processRecurringPayment(spaceId, newTransactionId)
    WeArePlanetAPI-->>PluginCore: SDK Transaction
    PluginCore-->>Scheduler: Domain Transaction (AUTHORIZED/PENDING)
```

### Running the Example

A working example is provided in the `example` directory.

> [!IMPORTANT]
> The recurring payment example relies on a transaction that has already been authorized. You should run the Checkout examples first, complete the payment in your browser, and then run the recurring script.

1. **Start Checkout**: Run `docs/Checkout/example/1_start_checkout.php`.
2. **Confirm & Pay**: Run `docs/Checkout/example/3_confirm_checkout.php` and follow the link to pay.
3. **Trigger Recurring**: Run `docs/Recurring/example/recurring.php`.
    * This script automatically detects the active session from the Checkout example.
    * Alternatively, you can pass the transaction ID manually:

      ```bash
      php recurring.php <transaction_id>
      ```
