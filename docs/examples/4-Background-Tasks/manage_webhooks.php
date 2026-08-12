<?php

namespace MyPlugin\ExampleWebhookImplementation;

/**
 * Webhook Management Example
 *
 * This script demonstrates the Webhook Management functionality:
 * Installing a Webhook (URL + Listener).
 * Listing Webhook URLs and Listeners.
 * Updating a Webhook URL.
 * Uninstalling a Webhook.
 *
 * USAGE:
 * php webhook.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../Common/bootstrap.php';

use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\WebhookManagementGateway;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;
use WeArePlanet\PluginCore\Transaction\State as TransactionState;
use WeArePlanet\PluginCore\Webhook\WebhookConfig;
use WeArePlanet\PluginCore\Webhook\WebhookService;

// 📖 Concept documentation: See docs/4-Background-Tasks/Webhook-Management.md

// Initialize Services via Bootstrap
$common = require __DIR__ . '/../Common/bootstrap.php';

$spaceId = $common['spaceId'];
$userId = $common['userId'];
$apiSecret = $common['apiSecret'];
$logger = $common['logger'];
$settings = $common['settings'];
$sdkProvider = $common['sdkProvider'];

// Setup Services
$managementGateway = new WebhookManagementGateway($sdkProvider, $logger);

$webhookService = new WebhookService(
    $managementGateway,
    $logger,
);

echo "Starting Webhook Management Demo in Space $spaceId...\n\n";

// Installation
echo "--- Installation ---\n";
// Use uniqid to keep the URL/name unique across runs (SDK constraint).
$uniqueId = uniqid();
$config = new WebhookConfig(
    url: 'https://example.com/webhook/callback?id=' . $uniqueId,
    name: 'Demo Webhook ' . $uniqueId,
    entity: WebhookListener::TRANSACTION,                // Entity enum
    eventStates: [TransactionState::AUTHORIZED->value],  // Array of states
);

$myUrl = null;
try {
    // installWebhook returns the created URL, so there is no need to re-query
    // and scan the (paginated) list of every URL in the space.
    $myUrl = $webhookService->installWebhook((int)$spaceId, $config);
    echo "SUCCESS: Webhook installed. URL ID: {$myUrl->id}, URL: {$myUrl->url}\n";
} catch (\Exception $e) {
    exit("FAILED: " . $e->getMessage() . "\n");
}

// Verification: fetch the listener for our URL (server-side filtered by URL id).
echo "\n--- Verification ---\n";
$myListener = null;
try {
    foreach ($webhookService->getWebhookListeners((int)$spaceId, $myUrl->id) as $listener) {
        if ($listener->name === $config->name) {
            $myListener = $listener;
            echo "Listener Found: ID=" . $listener->id . ", Entity=" . $listener->entityId . "\n";
            break;
        }
    }

    if (!$myListener) {
        exit("FAILED: Could not find the created webhook listener.\n");
    }
} catch (\Exception $e) {
    exit("FAILED: " . $e->getMessage() . "\n");
}

// Updating
echo "\n--- Updating ---\n";
$newUrl = 'https://example.com/updated-callback?id=' . uniqid();
try {
    $webhookService->updateWebhookUrl((int)$spaceId, $myUrl->id, $newUrl);
    echo "SUCCESS: URL updated to $newUrl.\n";
} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

// Uninstallation: remove the listener AND the URL definition so the webhook
// stops showing up in the WeArePlanet Portal.
echo "\n--- Uninstallation ---\n";
try {
    $webhookService->uninstallWebhook((int)$spaceId, $myUrl->id, $myListener->id);
    echo "SUCCESS: Webhook uninstalled.\n";
} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

echo "\nDemo Finished Successfully!\n";
