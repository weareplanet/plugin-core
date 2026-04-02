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

require_once __DIR__ . '/../../../vendor/autoload.php';
// The example requires a logger and settings provider to function. 
// These helper classes simulate a typical integration environment.

use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\SdkV2\PaymentMethodGateway;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Settings\SettingsProviderInterface;
use WeArePlanet\PluginCore\Settings\IntegrationMode as IntegrationModeEnum;
use WeArePlanet\PluginCore\LineItem\RoundingStrategy as RoundingStrategyEnum;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodService;

use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodRepositoryInterface;

// --- Helper Classes (Simulating the environment) ---

class SimpleRepository implements PaymentMethodRepositoryInterface
{
    public function sync(int $spaceId, array $paymentMethods): void
    {
        echo "[REPOSITORY] Syncing " . count($paymentMethods) . " methods for space $spaceId (No-op)\n";
    }
}

class SimpleLogger implements LoggerInterface
{
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        echo "[EMERGENCY] $message\n";
    }
    public function alert(string|\Stringable $message, array $context = []): void
    {
        echo "[ALERT] $message\n";
    }
    public function critical(string|\Stringable $message, array $context = []): void
    {
        echo "[CRITICAL] $message\n";
    }
    public function error(string|\Stringable $message, array $context = []): void
    {
        echo "[ERROR] $message\n";
    }
    public function warning(string|\Stringable $message, array $context = []): void
    {
        echo "[WARNING] $message\n";
    }
    public function notice(string|\Stringable $message, array $context = []): void
    {
        echo "[NOTICE] $message\n";
    }
    public function info(string|\Stringable $message, array $context = []): void
    {
        echo "[INFO] $message\n";
    }
    public function debug(string|\Stringable $message, array $context = []): void
    { /* echo "[DEBUG] $message\n"; */
    }
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        echo "[$level] $message\n";
    }
    public function __toString(): string
    {
        return 'SimpleLogger';
    }
}

class EnvSettingsProvider implements SettingsProviderInterface
{
    public function getSpaceId(): ?int
    {
        return (int)getenv('PLUGINCORE_DEMO_SPACE_ID') ?: null;
    }
    public function getUserId(): ?int
    {
        return (int)getenv('PLUGINCORE_DEMO_USER_ID') ?: null;
    }
    public function getApiKey(): ?string
    {
        return getenv('PLUGINCORE_DEMO_API_SECRET') ?: null;
    }
    public function getLogLevel(): ?string
    {
        return 'INFO';
    }
    public function getLineItemConsistencyEnabled(): ?bool
    {
        return true;
    }
    public function getLineItemRoundingStrategy(): ?RoundingStrategyEnum
    {
        return RoundingStrategyEnum::BY_LINE_ITEM;
    }
    public function getIntegrationMode(): IntegrationModeEnum
    {
        return IntegrationModeEnum::PAYMENT_PAGE;
    }
    public function getBaseUrl(): ?string
    {
        return getenv('PLUGINCORE_DEMO_BASE_URL') ?: null;
    }
}

// --- Main Execution ---

// Load and validate environment-based credentials.
$spaceId = getenv('PLUGINCORE_DEMO_SPACE_ID');
$userId = getenv('PLUGINCORE_DEMO_USER_ID');
$apiSecret = getenv('PLUGINCORE_DEMO_API_SECRET');

if (!$spaceId || !$userId || !$apiSecret) {
    exit("ERROR: Missing Credentials (PLUGINCORE_DEMO_SPACE_ID, PLUGINCORE_DEMO_USER_ID, PLUGINCORE_DEMO_API_SECRET).\n");
}

$spaceId = (int)$spaceId;

// Initialize the required service stack.
$logger = new SimpleLogger();
$repository = new SimpleRepository();
$settingsProvider = new EnvSettingsProvider();
$settings = new Settings($settingsProvider);

$sdkProvider = new SdkProvider($settings);
$gateway = new PaymentMethodGateway($sdkProvider, $logger);

$service = new PaymentMethodService($gateway, $repository, $logger);

echo "Starting Payment Method Retrieval in Space $spaceId...\n\n";

try {
    // By default, the gateway excludes 'DELETED' payment methods.
    // To retrieve all states (including DELETED), one would need to specify it if the gateway supports it, 
    // but usually, 'null' triggers the default exclusion of DELETED.
    $paymentMethods = $service->getPaymentMethods($spaceId);

    echo "Found " . count($paymentMethods) . " payment methods:\n";
    foreach ($paymentMethods as $paymentMethod) {
        $state = $paymentMethod->state;
        echo "- [ID: {$paymentMethod->id}] {$paymentMethod->name} (State: $state)\n";
        if ($paymentMethod->description) {
            echo "  Description: {$paymentMethod->description}\n";
        }
    }
} catch (\Exception $e) {
    exit("FAILED: " . $e->getMessage() . "\n");
}

echo "\nFinished Successfully!\n";
