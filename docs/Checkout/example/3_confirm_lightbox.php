<?php

namespace MyPlugin\ExampleCheckoutImplementation;

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../../examples/Common/bootstrap.php';

use WeArePlanet\PluginCore\Examples\Common\FilePersistence;
use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Render\IntegratedPaymentRenderService;
use WeArePlanet\PluginCore\Render\RenderOptions;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TransactionGateway;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Transaction\TransactionService;

// Force Lightbox Mode
putenv('PLUGINCORE_DEMO_INTEGRATION_MODE=lightbox');

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
$renderService = new IntegratedPaymentRenderService();

// 3. Load Session
try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\RuntimeException $e) {
    exit($e->getMessage() . "\n");
}

echo "Confirming Checkout for Transaction ID: $transactionId (Mode: Lightbox)\n";

// 4. Generate Simulation
try {
    $mode = 'lightbox';

    // Fetch Available Methods
    $paymentMethods = $gateway->getAvailablePaymentMethods((int)$spaceId, $transactionId);
    if (empty($paymentMethods)) {
        exit("\n[ERROR] No payment methods available for this transaction.\n");
    }

    // Pick the first one
    $method = reset($paymentMethods);
    echo "Selected Payment Method: " . $method->name . " (ID: " . $method->id . ")\n";

    // Get JS URL
    $javascriptUrl = $service->getPaymentUrl((int)$spaceId, $transactionId);

    // Render HTML Block using custom options.
    // The rendered script automatically registers the handler in a global registry:
    //   window.__weareplanetHandlers[configId]
    // This allows frontend frameworks (e.g., Alpine.js in Hyvä Checkout) to access
    // the handler for calling startPayment().
    $options = new RenderOptions(containerId: 'payment-form');
    $data = $renderService->getMetadata($javascriptUrl, $method->id, $mode);
    $blockHtml = $renderService->renderHtml($data, $options);

    // Load Host Template & Inject
    $templatePath = __DIR__ . '/resources/integrated_checkout_host.html';
    if (!file_exists($templatePath)) {
        exit("\n[ERROR] Host template not found at: $templatePath\n");
    }
    $templateHtml = file_get_contents($templatePath);
    $finalHtml = str_replace('{{content}}', $blockHtml, $templateHtml);

    // Save Simulation File
    $outputFile = __DIR__ . "/checkout_simulation_lightbox_{$transactionId}.html";
    file_put_contents($outputFile, $finalHtml);

    echo "\n---------------------------------------------------\n";
    echo "CHECKOUT SIMULATION READY (Lightbox)\n";
    echo "---------------------------------------------------\n";
    echo "HTML file generated at: $outputFile\n";
    echo "Open this file in your browser to test the Lightbox integration.\n";
    echo "---------------------------------------------------\n";
} catch (\Exception $e) {
    echo "\n[ERROR] Could not generate checkout simulation.\n";
    echo "Reason: " . $e->getMessage() . "\n";
    exit(1);
}
