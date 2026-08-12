<?php

namespace MyPlugin\ExampleCheckoutImplementation;

use WeArePlanet\PluginCore\Examples\Common\FilePersistence;
use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Render\IntegratedPaymentRenderService;
use WeArePlanet\PluginCore\Render\RenderOptions;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\TransactionGateway;
use WeArePlanet\PluginCore\Transaction\TransactionService;

// 📖 Concept documentation: See docs/2-Checkout-Flow/Checkout.md

error_reporting(E_ALL & ~E_DEPRECATED);

// Force IFrame Mode
putenv('PLUGINCORE_DEMO_INTEGRATION_MODE=iframe');

/** @var array $common */
$common = require __DIR__ . '/../Common/bootstrap.php';

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
$renderService = new IntegratedPaymentRenderService();

// Retrieve the transaction ID from the persistence storage to resume the session.
// We use the TransactionIdLoader to retrieve the ID from CLI arguments or the session.json file.
try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit("ERROR: No active session. Run '3_start_checkout.php' first.\n");
}

echo "Confirming Checkout for Transaction ID: $transactionId (Mode: IFrame)\n";

// Generate Simulation
try {
    $mode = 'iframe';

    // Generate the simulation HTML.
    // We pick the first available payment method and generate the payment URL.
    $paymentMethods = $gateway->getAvailablePaymentMethods((int)$spaceId, $transactionId,);
    if ($paymentMethods->isEmpty()) {
        exit("\n[ERROR] No payment methods available for this transaction.\n");
    }

    $method = $paymentMethods->first();
    echo "Selected Payment Method: " . $method->title->getDefault() . " (ID: " . $method->id . ")\n";

    $javascriptUrl = $service->getPaymentUrl((int)$spaceId, $transactionId)->value;
    // The rendered block registers the handler in window.__weareplanetHandlers[configId],
    // so frontend frameworks (e.g. Alpine.js) can access handler.validate() and handler.submit()
    // from outside the inline script.
    $data = $renderService->getMetadata($javascriptUrl, $method->id, $mode);
    $blockHtml = $renderService->renderHtml($data, new RenderOptions(containerId: 'payment-form'));

    // Load the host template and inject the rendered payment block.
    $templatePath = __DIR__ . '/resources/integrated_checkout_host.html';
    if (!file_exists($templatePath)) {
        exit("\n[ERROR] Host template not found at: $templatePath\n");
    }
    $templateHtml = file_get_contents($templatePath);
    $finalHtml = str_replace('{{content}}', $blockHtml, $templateHtml);

    // Save the generated simulation to an HTML file.
    $outputFile = __DIR__ . "/checkout_simulation_iframe_{$transactionId}.html";
    file_put_contents($outputFile, $finalHtml);

    echo "\n---------------------------------------------------\n";
    echo "CHECKOUT SIMULATION READY (IFrame)\n";
    echo "---------------------------------------------------\n";
    echo "HTML file generated at: $outputFile\n";
    echo "\nIMPORTANT: Due to browser security restrictions (CORS), checking out via 'file://' protocol\n";
    echo "will likely fail with 'postMessage' errors.\n";
    echo "\nPlease run the following command from the PROJECT ROOT:\n";
    echo "    php -S localhost:8000\n";
    echo "\nThen open:\n";
    echo "    http://localhost:8000/checkout_simulation_iframe_{$transactionId}.html\n";
    echo "---------------------------------------------------\n";
} catch (\Exception $e) {
    echo "\n[ERROR] Could not generate checkout simulation.\n";
    echo "Reason: " . $e->getMessage() . "\n";
    exit(1);
}
