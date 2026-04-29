<?php

namespace MyPlugin\ExampleCheckoutImplementation;

use WeArePlanet\PluginCore\Examples\Common\FilePersistence;
use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\TransactionGateway;
use WeArePlanet\PluginCore\Transaction\TransactionService;

error_reporting(E_ALL & ~E_DEPRECATED);

// Force Payment Page Mode
putenv('PLUGINCORE_DEMO_INTEGRATION_MODE=payment_page');

/** @var array $common */
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

$spaceId = $common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];
$settings = $common['settings'];
/** @var FilePersistence $persistence */
$persistence = $common['persistence'];

// Initialize required services.
$gateway = new TransactionGateway($sdkProvider, $logger, $settings);
$consistency = new LineItemConsistencyService($settings, $logger);
$service = new TransactionService($gateway, $consistency, $logger);

// Retrieve the transaction ID from the persistence storage to resume the session.
// We use the TransactionIdLoader to retrieve the ID from CLI arguments or the session.json file.
try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit("ERROR: No active session. Run '1_start_checkout.php' first.\n");
}

echo "Confirming Checkout for Transaction ID: $transactionId (Mode: Payment Page)\n";

// Generate the payment URL.
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
