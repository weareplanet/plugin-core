<?php

namespace MyPlugin\ExamplePaymentMethodImplementation;

/**
 * Payment Method Retrieval Example
 * 
 * This script demonstrates how to retrieve available payment methods
 * from the Portal for a specific space.
 * 
 * USAGE:
 * php retrieval.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

// Load common bootstrap which handles autoloading and shared helpers (SimpleLogger, EnvSettingsProvider)
$components = require_once __DIR__ . '/../../examples/Common/bootstrap.php';

use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodRepositoryInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodService;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\PaymentMethodGateway;

// --- Helper Classes (Simulating the environment) ---

class SimpleRepository implements PaymentMethodRepositoryInterface
{
    public function getExistingExternalIds(int $spaceId): array
    {
        return [];
    }

    public function create(PaymentMethod $method, int $spaceId): void
    {
        // No-op for retrieval example
    }

    public function update(PaymentMethod $method, int $spaceId): void
    {
        // No-op for retrieval example
    }

    public function deactivateByExternalId(int $externalId, int $spaceId): void
    {
        // No-op for retrieval example
    }
}

// --- Main Execution ---

$spaceId = $components['spaceId'];
$logger = $components['logger'];
$sdkProvider = $components['sdk_provider'];

$gateway = new PaymentMethodGateway($sdkProvider, $logger);
$repository = new SimpleRepository();

$service = new PaymentMethodService($gateway, $repository, $logger);

echo "Starting Payment Method Retrieval in Space $spaceId...\n\n";

try {
    $paymentMethods = $service->getPaymentMethods($spaceId);

    echo "Found " . count($paymentMethods) . " payment methods:\n";
    foreach ($paymentMethods as $paymentMethod) {
        $state = $paymentMethod->state->value;
        echo "- [ID: {$paymentMethod->id}] {$paymentMethod->name} (State: $state)\n";
        if ($paymentMethod->description) {
            echo "  Description: {$paymentMethod->description}\n";
        }
        echo "  Image Path: " . $paymentMethod->getRelativeImagePath() . "\n";
    }
} catch (\Exception $e) {
    exit("FAILED: " . $e->getMessage() . "\n");
}

echo "\nFinished Successfully!\n";
