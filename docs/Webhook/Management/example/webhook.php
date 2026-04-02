<?php

namespace MyPlugin\ExampleWebhookImplementation;

/**
 * Webhook Management Example
 * 
 * This script demonstrates the Webhook Management functionality:
 * 1. Installing a Webhook (URL + Listener).
 * 2. Listing Webhook URLs and Listeners.
 * 3. Updating a Webhook URL.
 * 4. Uninstalling a Webhook.
 * 
 * USAGE:
 * php webhook.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../../../examples/Common/bootstrap.php';

use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\SdkV2\WebhookManagementGateway;
use WeArePlanet\PluginCore\Sdk\SdkV2\WebhookSignatureGateway;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;
use WeArePlanet\PluginCore\Transaction\State as TransactionState;
use WeArePlanet\PluginCore\Webhook\WebhookConfig;
use WeArePlanet\PluginCore\Webhook\WebhookService;

// 1. Initialize Services via Bootstrap
$common = require __DIR__ . '/../../../examples/Common/bootstrap.php';

$spaceId = $common['spaceId'];
$userId = $common['userId'];
$apiSecret = $common['apiSecret'];
$logger = $common['logger'];
$settings = $common['settings'];
$sdkProvider = $common['sdkProvider'];

// 2. Setup Services
$managementGateway = new WebhookManagementGateway($sdkProvider, $logger);
$signatureGateway = new WebhookSignatureGateway($sdkProvider, $logger);

$webhookService = new WebhookService(
    $managementGateway,
    $signatureGateway,
    $logger
);

echo "Starting Webhook Management Demo in Space $spaceId...\n\n";

// 3. STEP 1: Installation
echo "--- STEP 1: Installing Webhook ---\n";
$config = new WebhookConfig(
    url: 'https://example.com/webhook/callback?id=' . uniqid(),
    name: 'Demo Webhook ' . uniqid(),
    entityId: WebhookListener::TRANSACTION->value, // Transaction
    eventStateId: TransactionState::AUTHORIZED->value       // Authorized
);

/** @var \WeArePlanet\PluginCore\Webhook\WebhookUrl $myUrl */
$myUrl = null;

try {
    $myUrl = $webhookService->installWebhook($spaceId, $config);
    echo "SUCCESS: Webhook installed. URL ID: {$myUrl->id}, URL: {$myUrl->url}\n";
} catch (\Exception $e) {
    exit("FAILED: " . $e->getMessage() . "\n");
}

// 4. STEP 2: Verification
echo "\n--- STEP 2: Verifying Webhooks ---\n";
try {
    // NEW: Direct fetch by ID (already have the URL object)
    echo "URL Found: ID=" . $myUrl->id . ", Name=" . $myUrl->name . ", URL=" . $myUrl->url . "\n";

    // Verify Listener by definition
    $myListenerId = $webhookService->getListenerId($spaceId, (int)$myUrl->id, WebhookListener::tryFrom($config->entityId), $config->eventStateId);

    if ($myListenerId) {
        echo "Listener Found: ID=" . $myListenerId . ", Entity=" . $config->entityId . "\n";
    } else {
        exit("FAILED: Could not find the created webhook listener for Entity {$config->entityId}.\n");
    }
} catch (\Exception $e) {
    exit("FAILED: " . $e->getMessage() . "\n");
}

// 5. STEP 3: Updating
echo "\n--- STEP 3: Updating Webhook URL ---\n";
$newUrl = 'https://example.com/updated-callback?id=' . uniqid();
try {
    $webhookService->updateWebhookUrl($spaceId, (int)$myUrl->id, $newUrl);
    echo "SUCCESS: URL updated to $newUrl.\n";
} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

// 6. STEP 4: Uninstallation
echo "\n--- STEP 4: Uninstalling Webhook ---\n";
try {
    // Uninstall by definition
    $webhookService->uninstallWebhook($spaceId, (int)$myUrl->id, WebhookListener::tryFrom($config->entityId), $config->eventStateId);
    echo "SUCCESS: Webhook uninstalled.\n";
} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

echo "\nDemo Finished Successfully!\n";
