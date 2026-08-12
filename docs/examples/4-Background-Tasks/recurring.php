<?php

namespace MyPlugin\ExampleRecurringImplementation;

/**
 * Recurring Payment Example
 *
 * This script demonstrates how to trigger a recurring payment (MIT) on an existing transaction.
 *
 * USAGE:
 * php recurring.php [transaction_id]
 */

use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\RecurringTransactionGateway;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\TokenGateway;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\TransactionGateway;
use WeArePlanet\PluginCore\Token\Exception\TokenException;
use WeArePlanet\PluginCore\Token\TokenService;
use WeArePlanet\PluginCore\Transaction\RecurringTransactionService;
use WeArePlanet\PluginCore\Transaction\TransactionService;

// 📖 Concept documentation: See docs/4-Background-Tasks/Recurring.md

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../Common/bootstrap.php';

$spaceId = $common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];
$settings = $common['settings'];
// Load the original transaction ID for the recurring payment.
try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit($e->getMessage());
}

// Setup the required services for processing recurring payments.
$transactionGateway = new TransactionGateway($sdkProvider, $logger, $settings);
$recurringGateway = new RecurringTransactionGateway($sdkProvider, $logger);
$consistencyService = new LineItemConsistencyService($settings, $logger);

$transactionService = new TransactionService($transactionGateway, $consistencyService, $logger);
$tokenService = new TokenService(new TokenGateway($sdkProvider, $logger), $logger);

$recurringService = new RecurringTransactionService(
    $transactionService,
    $recurringGateway,
    $logger
);

echo "Attempting to Process Recurring Payment for Transaction ID: $transactionId\n";

// Execute the recurring payment processing.
try {
    // Check if token exists. If not, manually create it using TokenService.
    // createTokenForTransaction now throws a TokenException on failure (with a
    // localized reason) instead of silently returning null.
    $originalTransaction = $transactionService->getTransaction((int)$spaceId, $transactionId);
    if ($originalTransaction->token === null) {
        echo "No token found on transaction $transactionId. Attempting to create one manually via TokenService...\n";
        $token = $tokenService->createTokenForTransaction((int)$spaceId, $transactionId);
        $originalTransaction->token = $token;
        echo "Successfully created token {$token->id}.\n";
    }

    // A token carries two customer fields, and they answer different questions.
    // customerId is the shop's own customer reference, round-tripped through the
    // WeArePlanet Portal — the key to scope a "saved payment methods" list by. customerIdentifier
    // is a display label only (an email address or masked card number), so keying a
    // customer-scoped lookup off it risks showing one customer another's cards.
    $savedToken = $originalTransaction->token;
    if ($savedToken !== null) {
        echo "Token {$savedToken->id} shop customer ID: "
            . ($savedToken->customerId ?? '(none - created without a customer reference)') . "\n";
        echo "Token {$savedToken->id} display identifier: "
            . ($savedToken->customerIdentifier ?? '(none)') . "\n";
    }

    // The active version says which merchant-configured payment method these
    // credentials were created under. The *Configuration IDs are the space-scoped
    // ones — paymentMethodConfigurationId is what matches PaymentMethod::$id, so it
    // is the key to a plugin's locally synced payment-method record. connectorId and
    // paymentMethodId next to them are the global types, and will not join against it.
    if ($savedToken !== null) {
        $activeVersion = $tokenService->getActiveTokenVersion((int)$spaceId, $savedToken->id);
        if ($activeVersion !== null) {
            echo "Active version {$activeVersion->id}:\n";
            echo "  payment method type:          " . ($activeVersion->paymentMethodId ?? '(none)') . "\n";
            echo "  payment method configuration: " . ($activeVersion->paymentMethodConfigurationId ?? '(none)') . "\n";
            echo "  connector type:               " . ($activeVersion->connectorId ?? '(none)') . "\n";
            echo "  connector configuration:      " . ($activeVersion->connectorConfigurationId ?? '(none)') . "\n";
        }
    }

    $newTransaction = $recurringService->processRecurringPayment((int)$spaceId, $transactionId);

    echo "---------------------------------------------------\n";
    echo "RECURRING PAYMENT PROCESSED\n";
    echo "---------------------------------------------------\n";
    echo "New Transaction ID: " . $newTransaction->id . "\n";
    echo "New State:          " . $newTransaction->state->value . "\n";
    // The failure reason is now preserved on recurring charges that resolve to FAILED.
    if ($newTransaction->failureReason !== null) {
        echo "Failure Reason:     " . $newTransaction->failureReason->localize('en-US') . "\n";
    }
    echo "---------------------------------------------------\n";
} catch (TokenException $e) {
    echo "---------------------------------------------------\n";
    echo "TOKEN CREATION FAILED\n";
    echo "---------------------------------------------------\n";
    echo "Reason: " . ($e->getLocalizedMessage()?->localize('en-US') ?? $e->getMessage()) . "\n";
    echo "---------------------------------------------------\n";
    exit(1);
} catch (\Throwable $e) {
    echo "---------------------------------------------------\n";
    echo "RECURRING PAYMENT FAILED\n";
    echo "---------------------------------------------------\n";
    echo "Reason: " . $e->getMessage() . "\n";
    echo "Hint: Ensure the original transaction was successful and has a valid token.\n";
    echo "---------------------------------------------------\n";
    exit(1);
}
