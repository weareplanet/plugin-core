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
use WeArePlanet\PluginCore\Sdk\SdkV1\RecurringTransactionGateway;
use WeArePlanet\PluginCore\Sdk\SdkV1\TokenGateway;
use WeArePlanet\PluginCore\Sdk\SdkV1\TransactionGateway;
use WeArePlanet\PluginCore\Token\TokenService;
use WeArePlanet\PluginCore\Transaction\RecurringTransactionService;
use WeArePlanet\PluginCore\Transaction\TransactionService;

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

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
    $tokenService,
    $logger
);

echo "Attempting to Process Recurring Payment for Transaction ID: $transactionId\n";

// Execute the recurring payment processing.
try {
    $newTransaction = $recurringService->processRecurringPayment((int)$spaceId, $transactionId);

    echo "---------------------------------------------------\n";
    echo "RECURRING PAYMENT PROCESSED\n";
    echo "---------------------------------------------------\n";
    echo "New Transaction ID: " . $newTransaction->id . "\n";
    echo "New State:          " . $newTransaction->state->value . "\n";
    echo "---------------------------------------------------\n";
} catch (\Throwable $e) {
    echo "---------------------------------------------------\n";
    echo "RECURRING PAYMENT FAILED\n";
    echo "---------------------------------------------------\n";
    echo "Reason: " . $e->getMessage() . "\n";
    echo "Hint: Ensure the original transaction was successful and has a valid token.\n";
    echo "---------------------------------------------------\n";
    exit(1);
}
