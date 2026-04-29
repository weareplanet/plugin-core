<?php

namespace MyPlugin\ExampleCheckoutImplementation;

use WeArePlanet\PluginCore\Examples\Common\FilePersistence;
use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Render\IntegratedPaymentRenderService;
use WeArePlanet\PluginCore\Render\RenderOptions;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\TransactionGateway;
use WeArePlanet\PluginCore\Transaction\TransactionService;

error_reporting(E_ALL & ~E_DEPRECATED);

// Force IFrame Mode for this custom UI demo
putenv('PLUGINCORE_DEMO_INTEGRATION_MODE=iframe');

/** @var array $common */
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

$spaceId = $common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];
$settings = $common['settings'];

// Initialize required services.
$gateway = new TransactionGateway($sdkProvider, $logger, $settings);
$consistency = new LineItemConsistencyService($settings, $logger);
$service = new TransactionService($gateway, $consistency, $logger);
$renderService = new IntegratedPaymentRenderService();

// Retrieve the transaction ID from the persistence storage to resume the session.
try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit("ERROR: No active session. Run '1_start_checkout.php' first.\n");
}

echo "Confirming Checkout for Transaction ID: $transactionId (Mode: Custom UI / Reactive)\n";

try {
    $mode = 'iframe';
    $paymentMethods = $gateway->getAvailablePaymentMethods((int)$spaceId, $transactionId);
    if (empty($paymentMethods)) {
        exit("\n[ERROR] No payment methods available for this transaction.\n");
    }

    $method = reset($paymentMethods);
    echo "Selected Payment Method: " . $method->name . " (ID: " . $method->id . ")\n";

    $javascriptUrl = $service->getPaymentUrl((int)$spaceId, $transactionId);

    // Get the raw metadata. This is useful for reactive frameworks (Vue, React, Alpine.js)
    // that might want to store these values in their state.
    $data = $renderService->getMetadata($javascriptUrl, $method->id, $mode);

    // Generate only the JavaScript tags. 
    // We also demonstrate how to provide a CSP nonce for secure environments.
    $cspNonce = 'demo-nonce-' . bin2hex(random_bytes(8));
    $options = new RenderOptions(
        containerId: 'custom-payment-container',
        buttonText: 'Pay with Custom UI',
        nonce: $cspNonce
    );
    $jsTags = $renderService->renderJs($data, $options);

    // Build a custom HTML structure. 
    // In a real application, this would be part of your frontend template.
    // Note that we must match the IDs provided in RenderOptions.
    $customUiHtml = <<<HTML
<div class="custom-payment-form">
    <h3>Custom Payment Experience</h3>
    <p>This UI is built manually, but powered by the standardized RenderService logic.</p>
    
    <!-- The container where the IFrame will be injected -->
    <div id="custom-payment-container" style="border: 1px solid #ccc; padding: 10px; border-radius: 4px;"></div>
    
    <!-- Container for validation errors -->
    <div id="custom-payment-container_errors" style="color: red; margin: 10px 0;"></div>
    
    <!-- The submit button -->
    <button id="custom-payment-container_submit" style="background-color: #28a745; color: white; border: none; padding: 12px 24px; border-radius: 4px; cursor: pointer;">
        Submit Secure Payment
    </button>
</div>

<!-- Standardized JavaScript initialization logic with CSP nonce -->
{$jsTags}
HTML;

    // Load the host template and inject our custom block.
    $templatePath = __DIR__ . '/resources/integrated_checkout_host.html';
    $templateHtml = file_exists($templatePath) ? file_get_contents($templatePath) : '<html><body>{{content}}</body></html>';
    $finalHtml = str_replace('{{content}}', $customUiHtml, $templateHtml);

    // Save the generated simulation.
    $outputFile = __DIR__ . "/checkout_simulation_custom_ui_{$transactionId}.html";
    file_put_contents($outputFile, $finalHtml);

    echo "\n---------------------------------------------------\n";
    echo "CUSTOM UI SIMULATION READY\n";
    echo "---------------------------------------------------\n";
    echo "HTML file generated at: $outputFile\n";
    echo "This example uses a CSP nonce: $cspNonce\n";
    echo "\nPlease run: php -S localhost:8000\n";
    echo "Then open: http://localhost:8000/checkout_simulation_custom_ui_{$transactionId}.html\n";
    echo "---------------------------------------------------\n";

} catch (\Exception $e) {
    echo "\n[ERROR] Could not generate checkout simulation.\n";
    echo "Reason: " . $e->getMessage() . "\n";
    exit(1);
}
