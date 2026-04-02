<?php

declare(strict_types=1);

namespace MyPlugin\ExampleWebhookImplementation;

require_once __DIR__ . '/../../../../vendor/autoload.php';

// Load Implementation Classes
require_once __DIR__ . '/src/MyExampleSettingsProvider.php';
require_once __DIR__ . '/src/MyExampleLifecycleHandler.php';
require_once __DIR__ . '/src/MyPluginLogger.php';
require_once __DIR__ . '/src/MyExampleStateMapper.php';
require_once __DIR__ . '/src/ExampleStateFetcher.php';
// Transaction
require_once __DIR__ . '/src/Transaction/GenericCommand.php';
require_once __DIR__ . '/src/Transaction/AuthorizedCommand.php';
require_once __DIR__ . '/src/Transaction/FulfillCommand.php';
require_once __DIR__ . '/src/Transaction/TransactionListener.php';
// Refund
require_once __DIR__ . '/src/Refund/SuccessfulCommand.php';
require_once __DIR__ . '/src/Refund/RefundListener.php';

use WeArePlanet\PluginCore\Http\Request;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;
use WeArePlanet\PluginCore\Webhook\Listener\WebhookListenerRegistry;
use WeArePlanet\PluginCore\Webhook\StateValidator;
use WeArePlanet\PluginCore\Webhook\WebhookProcessor;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use MyPlugin\ExampleWebhookImplementation\Transaction\TransactionListener;
use MyPlugin\ExampleWebhookImplementation\Refund\RefundListener;

// --- CLI Argument Parsing ---
$debugMode = in_array('--debug', $argv);
$logLevel = $debugMode ? 'DEBUG' : 'INFO';

echo "========================================\n";
echo "  Webhook Example (Level: $logLevel) \n";
echo "  Usage: php index.php [--debug]\n";
echo "========================================\n\n";


// Initialize core services and providers.
$settingsProvider = new MyExampleSettingsProvider();
$settings = new Settings($settingsProvider);
$sdkProvider = new SdkProvider($settings);
$mapper = new MyExampleStateMapper();
$lifecycleHandler = new MyExampleLifecycleHandler($mapper);
$logger = new MyPluginLogger($logLevel);
$stateFetcher = new ExampleStateFetcher();
$registry = new WebhookListenerRegistry();
$validator = new StateValidator();

// Register listeners for different webhook event states.
// Transaction Listener (Handles multiple states via internal routing)
$txListener = new TransactionListener($logger);
$txStates = ['CREATE', 'PENDING', 'CONFIRMED', 'PROCESSING', 'AUTHORIZED', 'COMPLETED', 'FULFILL'];
foreach ($txStates as $state) {
    $registry->addListener(WebhookListener::TRANSACTION, $state, $txListener);
}

// Refund Listener
$rfListener = new RefundListener($logger);
$registry->addListener(WebhookListener::REFUND, 'SUCCESSFUL', $rfListener);

// Instantiate the main webhook processor.
$processor = new WebhookProcessor($registry, $validator, $lifecycleHandler, $stateFetcher, $logger);

// --- Scenario 1: Transaction Happy Path ---
echo "SCENARIO 1: Transaction Lifecycle\n";
echo "---------------------------------\n";
// Simulate receiving these webhooks in sequence
$entityId = 456;
$sequence = ['CREATE', 'PENDING', 'AUTHORIZED', 'FULFILL']; // Skipping some to show catch-up

foreach ($sequence as $state) {
    processWebhook($processor, 'Transaction', $state, $entityId);
    sleep(2);
}

echo "\n";

// --- Scenario 2: Refund ---
echo "SCENARIO 2: Refund Successful\n";
echo "-----------------------------\n";
$refundId = 999;
processWebhook($processor, 'Refund', 'SUCCESSFUL', $refundId);


// --- Helper Functions ---

function processWebhook(WebhookProcessor $processor, string $technicalName, string $state, int $entityId): void
{
    // Simulate HTTP Request
    $request = create_mock_request($technicalName, $state, $entityId);

    echo "-> Incoming Webhook: {$technicalName} / {$state}\n";
    try {
        $processor->process($request);
    } catch (\Exception $e) {
        echo "   [ERROR] " . $e->getMessage() . "\n";
    }
    echo "\n";
}

function create_mock_request(string $technicalName, string $state, int $entityId): Request
{
    $mockBody = [
        'listenerEntityTechnicalName' => $technicalName,
        'state' => $state,
        'entityId' => $entityId,
        'spaceId' => 12345,
    ];
    $reflection = new \ReflectionClass(Request::class);
    $constructor = $reflection->getConstructor();
    $constructor->setAccessible(true);
    $request = $reflection->newInstanceWithoutConstructor();
    $constructor->invoke($request, [], $mockBody, json_encode($mockBody));
    return $request;
}
