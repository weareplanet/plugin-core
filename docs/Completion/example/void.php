<?php

namespace MyPlugin\ExampleVoidImplementation;

/**
 * Void Example
 *
 * This script demonstrates how to void an authorized transaction.
 *
 * USAGE:
 * php void.php [transaction_id]
 */

use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\TransactionCompletionGateway;
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

// Setup the required services for transaction completion.
$gateway = new TransactionCompletionGateway($sdkProvider);
$service = new TransactionCompletionService($gateway, $logger);

// Execute the void operation for the transaction.
try {
    echo "Voiding Transaction $transactionId..." . PHP_EOL;
    $state = $service->void((int)$spaceId, $transactionId);
    echo "Result: Void state is $state" . PHP_EOL;
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
