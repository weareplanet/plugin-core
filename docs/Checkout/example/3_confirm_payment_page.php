<?php

namespace MyPlugin\ExampleCheckoutImplementation;

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../../examples/Common/bootstrap.php';

use WeArePlanet\PluginCore\Examples\Common\FilePersistence;
use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\SdkV2\TransactionGateway;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Transaction\TransactionService;

// Force Payment Page Mode
putenv('PLUGINCORE_DEMO_INTEGRATION_MODE=payment_page');

// 1. Initialize Services via Bootstrap
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

$spaceId = $common['spaceId'];
$userId = $common['userId'];
$apiSecret = $common['apiSecret'];
$logger = $common['logger'];
$settings = $common['settings'];
$sdkProvider = $common['sdkProvider'];

// 2. Services
// FilePersistence is now in Common, but we might want to use a local session file
$persistence = new FilePersistence(__DIR__ . '/session.json');

$gateway = new TransactionGateway($sdkProvider, $logger, $settings);
$consistency = new LineItemConsistencyService($settings, $logger);

$service = new TransactionService($gateway, $consistency, $logger);

// 3. Load Session
try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\RuntimeException $e) {
    exit($e->getMessage() . "\n");
}

echo "Confirming Checkout for Transaction ID: $transactionId (Mode: Payment Page)\n";

// 4. Generate URL
try {
    $paymentUrl = $service->getPaymentUrl((int)$spaceId, $transactionId);

    echo "\n---------------------------------------------------\n";
    echo "CHECKOUT READY\n";
    echo "---------------------------------------------------\n";
    echo "Please open this URL in your browser to pay:\n\n";
    echo $paymentUrl . "\n\n";
    echo "---------------------------------------------------\n";
} catch (\Exception $e) {
    echo "\n[ERROR] Could not generate payment URL.\n";
    echo "Reason: " . $e->getMessage() . "\n";
    exit(1);
}
