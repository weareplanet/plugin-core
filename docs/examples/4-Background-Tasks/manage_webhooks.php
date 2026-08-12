<?php

namespace MyPlugin\ExampleWebhookImplementation;

/**
 * Webhook Management Example
 *
 * This script demonstrates the Webhook Management functionality:
 * This script demonstrates the Webhook Management functionality:
 * - Installing a Webhook (URL + Listener).
 * - Listing Webhook URLs and Listeners.
 * - Updating a Webhook URL.
 * - Uninstalling a Webhook.
 *
 * USAGE:
 * php webhook.php
 */

use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\WebhookManagementGateway;
use WeArePlanet\PluginCore\Transaction\State as TransactionState;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;
use WeArePlanet\PluginCore\Webhook\WebhookConfig;
use WeArePlanet\PluginCore\Webhook\WebhookService;

// 📖 Concept documentation: See docs/4-Background-Tasks/Webhook-Management.md

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../Common/bootstrap.php';

$spaceId = $common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];
$settings = $common['settings'];
// Webhook example doesn't need persistence or argLoader typically, but they are available.

// Setup the webhook management service.
$managementGateway = new WebhookManagementGateway($sdkProvider, $logger);

$webhookService = new WebhookService(
    $managementGateway,
    $logger,
);

echo "Starting Webhook Management Demo in Space $spaceId...\n\n";

// Install the webhook configuration (URL and Listener) in the WeArePlanet Portal.
echo "--- STEP 1: Installing Webhook ---\n";
// Use uniqid to ensure URL is unique (SDK constraint)
$uniqueId = uniqid();
$config = new WebhookConfig(
    url: 'https://example.com/webhook/callback/' . $uniqueId,
    name: 'Demo Webhook ' . $uniqueId,
    entity: WebhookListener::TRANSACTION, // Enum
    eventStates: [TransactionState::AUTHORIZED->value], // Array of states
);

$myUrl = null;
try {
    // installWebhook returns the created URL, so there is no need to scan the
    // (paginated) list of every URL in the space to find it afterwards.
    $myUrl = $webhookService->installWebhook((int)$spaceId, $config);
    echo "SUCCESS: Webhook installed. URL ID: {$myUrl->id}, URL: {$myUrl->url}\n";
} catch (\Exception $e) {
    exit("FAILED: " . $e->getMessage() . "\n");
}

// List the existing webhook configurations and locate our listener.
echo "\n--- STEP 2: Listing Webhooks ---\n";
$myListener = null;
try {
    $urls = $webhookService->listUrls((int)$spaceId);
    echo "Found " . count($urls) . " Webhook URL(s).\n";
    echo "URL: ID={$myUrl->id}, Name={$myUrl->name}, URL={$myUrl->url}\n";

    // Fetch the listener for our URL (server-side filtered by URL id).
    foreach ($webhookService->getWebhookListeners((int)$spaceId, $myUrl->id) as $listener) {
        if ($listener->name === $config->name) {
            $myListener = $listener;
            echo "Listener Found: ID=" . $listener->id . ", Name=" . $listener->name . "\n";
            break;
        }
    }
} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

// Update the URL of an existing webhook configuration.
if ($myUrl) {
    echo "\n--- STEP 3: Updating Webhook URL ---\n";
    try {
        $newUrl = 'https://example.com/webhook/v2/' . uniqid(); // Ensure uniqueness
        $webhookService->updateWebhookUrl((int)$spaceId, $myUrl->id, $newUrl);
        echo "SUCCESS: Webhook URL updated to: $newUrl\n";
    } catch (\Exception $e) {
        echo "FAILED to update URL: " . $e->getMessage() . "\n";
    }
}

// Uninstall the webhook by removing its listeners AND the URL definition.
// Deleting only the listeners would leave the URL registered in the WeArePlanet Portal,
// so the webhook keeps showing up. deleteWebhookUrl(..., cascade: true) first
// removes every listener attached to the URL and then deletes the URL itself.
if ($myUrl) {
    echo "\n--- STEP 4: Uninstalling (Cleanup) ---\n";
    try {
        $deletedListeners = $webhookService->deleteWebhookUrl((int)$spaceId, $myUrl->id, cascade: true);
        echo "SUCCESS: Removed $deletedListeners listener(s) and Webhook URL ID " . $myUrl->id . ".\n";
    } catch (\Exception $e) {
        echo "FAILED to uninstall: " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n";
