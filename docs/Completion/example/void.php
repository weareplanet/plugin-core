<?php

namespace MyPlugin\ExampleCaptureImplementation;

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../../examples/Common/bootstrap.php';

use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\SdkV2\TransactionCompletionGateway;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletionService;
use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;

// 1. Initialize Services via Bootstrap
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

$spaceId = $common['spaceId'];
$userId = $common['userId'];
$apiSecret = $common['apiSecret'];
$logger = $common['logger'];
$settings = $common['settings'];
$sdkProvider = $common['sdkProvider'];

// 2. Load Transaction ID
try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit(1);
}

// 3. Setup Services
$gateway = new TransactionCompletionGateway($sdkProvider);
$service = new TransactionCompletionService($gateway, $logger);

// 4. Void Transaction
try {
    echo "Voiding Transaction $transactionId..." . PHP_EOL;
    $state = $service->void((int)$spaceId, $transactionId);
    echo "Result: Void state is $state" . PHP_EOL;
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
