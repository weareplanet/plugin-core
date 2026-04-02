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

use WeArePlanet\PluginCore\Sdk\SdkV1\WebhookManagementGateway;
use WeArePlanet\PluginCore\Sdk\SdkV1\WebhookSignatureGateway;
use WeArePlanet\PluginCore\Transaction\State as TransactionState;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;
use WeArePlanet\PluginCore\Webhook\WebhookConfig;
use WeArePlanet\PluginCore\Webhook\WebhookService;

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../../../examples/Common/bootstrap.php';

$spaceId = $common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];
$settings = $common['settings'];
// Webhook example doesn't need persistence or argLoader typically, but they are available.

// Setup the required webhook management and signature services.
$managementGateway = new WebhookManagementGateway($sdkProvider, $logger);
$signatureGateway = new WebhookSignatureGateway($sdkProvider, $logger);

$webhookService = new WebhookService(
    $managementGateway,
    $signatureGateway,
    $logger
);

echo "Starting Webhook Management Demo in Space $spaceId...\n\n";

// Install the webhook configuration (URL and Listener) in the portal.
echo "--- STEP 1: Installing Webhook ---\n";
// Use uniqid to ensure URL is unique (SDK constraint)
$uniqueId = uniqid();
$config = new WebhookConfig(
    url: 'https://example.com/webhook/callback/' . $uniqueId,
    name: 'Demo Webhook ' . $uniqueId,
    entity: WebhookListener::TRANSACTION, // Enum
    eventStates: [TransactionState::AUTHORIZED->value] // Array of states
);

try {
    $webhookService->installWebhook((int)$spaceId, $config);
    echo "SUCCESS: Webhook installed.\n";
} catch (\Exception $e) {
    exit("FAILED: " . $e->getMessage() . "\n");
}

// List the existing webhook configurations for the space.
echo "\n--- STEP 2: Listing Webhooks ---\n";
try {
    $urls = $webhookService->listUrls((int)$spaceId);

    echo "Found " . count($urls) . " Webhook URL(s).\n";

    // Find our recently created URL and Listener
    $myUrl = null;
    foreach ($urls as $url) {
        if ($url->name === $config->name) {
            $myUrl = $url;
            echo "URL Found: ID=" . $url->id . ", Name=" . $url->name . ", URL=" . $url->url . "\n";
            break;
        }
    }

    $myListener = null;
    if ($myUrl) {
        // Fetch listeners specifically for this URL
        $listeners = $webhookService->getWebhookListeners((int)$spaceId, $myUrl->id);
        foreach ($listeners as $listener) {
            // In the installWebhook method, we use the same name for the listener and URL
            if ($listener->name === $config->name) {
                $myListener = $listener;
                echo "Listener Found: ID=" . $listener->id . ", Name=" . $listener->name . "\n";
                break;
            }
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

// Uninstall the webhook by removing its listeners.
// This ensures that the system stops sending events to the callback URL.
if ($myUrl) {
    echo "\n--- STEP 4: Uninstalling (Cleanup) ---\n";
    try {
        // Remove all listeners associated with the URL.
        $webhookService->deleteWebhookListenersForUrl((int)$spaceId, $myUrl->id);
        echo "SUCCESS: Webhook Listeners removed for URL ID " . $myUrl->id . ".\n";

    } catch (\Exception $e) {
        echo "FAILED to uninstall: " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n";
