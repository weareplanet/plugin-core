<?php

namespace MyPlugin\ExampleCaptureImplementation;

/**
 * Capture Example
 *
 * This script demonstrates how to capture an authorized transaction.
 *
 * USAGE:
 * php capture.php [transaction_id]
 */

use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\Sdk\SdkV1\TransactionCompletionGateway;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletionService;

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

$spaceId = $common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];
$settings = $common['settings'];
// Load the transaction ID from command line arguments or environment.
try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit($e->getMessage());
}

// Setup required services for transaction completion.
$completionGateway = new TransactionCompletionGateway($sdkProvider);
$completionService = new TransactionCompletionService($completionGateway, $logger);

echo "Attempting to Capture Transaction ID: $transactionId\n";

// Execute the capture operation.
try {
    $completion = $completionService->capture((int)$spaceId, $transactionId);

    echo "---------------------------------------------------\n";
    echo "CAPTURE SUCCESSFUL\n";
    echo "---------------------------------------------------\n";
    echo "Completion ID: " . $completion->id . "\n";
    echo "New State:     " . $completion->state->value . "\n";
    echo "---------------------------------------------------\n";
} catch (\Exception $e) {
    echo "---------------------------------------------------\n";
    echo "CAPTURE FAILED\n";
    echo "---------------------------------------------------\n";
    echo "Reason: " . $e->getMessage() . "\n";
    echo "Hint: Ensure you have completed the payment in the browser first.\n";
    echo "---------------------------------------------------\n";
    exit(1);
}
