<?php

/**
 * Payment Method Synchronization Example
 *
 * Demonstrates the two ways to consume payment methods through PluginCore:
 *
 * Way 1 — Direct retrieval: Fetch methods from the API on-the-fly via $service->getPaymentMethods().
 *         Use this for real-time checkout scenarios where you need the latest state.
 *
 * Way 2 — Synchronization: Persist methods to a local database via $service->synchronize().
 *         Use this for cron jobs or admin triggers where you want to keep a local mirror
 *         of the API state. You implement a repository with basic CRUD — PluginCore handles the diff.
 *
 * USAGE:
 *   export PLUGINCORE_DEMO_SPACE_ID=<your-space-id>
 *   export PLUGINCORE_DEMO_USER_ID=<your-user-id>
 *   export PLUGINCORE_DEMO_API_SECRET=<your-api-key>
 *   php 2_synchronize_methods.php
 */

namespace MyPlugin\ExampleSynchronization;

error_reporting(E_ALL & ~E_DEPRECATED);

// Load common bootstrap which handles autoloading and shared helpers (SimpleLogger, EnvSettingsProvider)
$components = require_once __DIR__ . '/../../examples/Common/bootstrap.php';

use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodRepositoryInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodService;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\PaymentMethodGateway;

// --- Helper Classes (Simulating the environment) ---

/**
 * Dummy repository that echoes SQL-like operations instead of hitting a real database.
 *
 * This is the class a plugin developer would replace with real persistence logic.
 * Notice how simple the contract is — PluginCore tells you exactly what to do.
 */
class DummySqlRepository implements PaymentMethodRepositoryInterface
{
    /**
     * Simulates querying local payment method IDs.
     * In a real plugin, this would be: SELECT external_id FROM payment_methods WHERE space_id = ?
     *
     * @return int[]
     */
    public function getExistingExternalIds(int $spaceId): array
    {
        // Pretend we already have methods 100 and 200 stored locally.
        $existingIds = [100, 200];
        echo sprintf("[DB] SELECT external_id FROM payment_methods WHERE space_id = %d → [%s]\n", $spaceId, implode(', ', $existingIds));

        return $existingIds;
    }

    public function create(PaymentMethod $method, int $spaceId): void
    {
        echo sprintf(
            "[DB] INSERT INTO payment_methods (external_id, space_id, name) VALUES (%d, %d, '%s')\n",
            $method->id,
            $spaceId,
            $method->name,
        );
    }

    public function update(PaymentMethod $method, int $spaceId): void
    {
        echo sprintf(
            "[DB] UPDATE payment_methods SET name = '%s' WHERE external_id = %d AND space_id = %d\n",
            $method->name,
            $method->id,
            $spaceId,
        );
    }

    public function deactivateByExternalId(int $externalId, int $spaceId): void
    {
        echo sprintf(
            "[DB] UPDATE payment_methods SET active = 0 WHERE external_id = %d AND space_id = %d\n",
            $externalId,
            $spaceId,
        );
    }
}

// --- Main Execution ---

$spaceId = $components['spaceId'];
$logger = $components['logger'];
$sdkProvider = $components['sdk_provider'];

$gateway = new PaymentMethodGateway($sdkProvider, $logger);

// The repository is where your plugin plugs in — implement the 4 interface methods.
$repository = new DummySqlRepository();

$service = new PaymentMethodService($gateway, $repository, $logger);

// ─── Way 1: Direct API Retrieval ───────────────────────────────────────────────
// Use this when you need fresh data on-the-fly (e.g., during checkout).
echo "═══ Way 1: Direct API Retrieval ═══\n\n";

try {
    $paymentMethods = $service->getPaymentMethods($spaceId);

    echo sprintf("Found %d payment methods:\n", count($paymentMethods));
    foreach ($paymentMethods as $paymentMethod) {
        echo sprintf("  - [ID: %d] %s (State: %s)\n", $paymentMethod->id, $paymentMethod->name, $paymentMethod->state->value);
    }
} catch (\Exception $e) {
    exit("FAILED: " . $e->getMessage() . "\n");
}

// ─── Way 2: Synchronization Loop ──────────────────────────────────────────────
// Use this in cron jobs or admin actions to keep your local DB in sync with the API.
// PluginCore handles the diffing — your repository just does INSERT/UPDATE/DEACTIVATE.
echo "\n═══ Way 2: Synchronization Loop ═══\n\n";

try {
    $service->synchronize($spaceId);
} catch (\Exception $e) {
    exit("FAILED: " . $e->getMessage() . "\n");
}

echo "\nFinished Successfully!\n";
