<?php

namespace MyPlugin\ExampleCaptureImplementation;

/**
 * Capture Example
 * 
 * This script demonstrates how to capture an authorized transaction.
 * 
 * USAGE:
 * php capture.php [session_file_or_dir] [transaction_id]
 * 
 * See src/TransactionIdLoader.php for argument handling details.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../../examples/Common/bootstrap.php';

use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TransactionCompletionGateway;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletionService;
use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;

// Initialize Services via Bootstrap
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

$spaceId = $common['spaceId'];
$userId = $common['userId'];
$apiSecret = $common['apiSecret'];
$logger = $common['logger'];
$settings = $common['settings'];
$sdkProvider = $common['sdkProvider'];

// Load Transaction ID
try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit(1);
}

// Setup Services
$completionGateway = new TransactionCompletionGateway($sdkProvider, $logger);

$completionService = new TransactionCompletionService($completionGateway, $logger);

echo "Attempting to Capture Transaction ID: $transactionId\n";

// Execute Capture
try {
    $completion = $completionService->capture((int)$spaceId, $transactionId);

    echo "---------------------------------------------------\n";
    echo "CAPTURE SUCCESSFUL\n";
    echo "---------------------------------------------------\n";
    echo "Completion ID: " . $completion->id . "\n";
    echo "New State:     " . $completion->state->value . "\n";
    // If the gateway reported a failure reason, localize it for the shop locale.
    if ($completion->failureReason !== null) {
        $shopLocale = 'en-US';
        echo "Failure Reason: " . $completion->failureReason->localize($shopLocale) . "\n";
    }
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
