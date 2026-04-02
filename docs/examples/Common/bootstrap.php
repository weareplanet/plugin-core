<?php

/**
 * Common Bootstrap for PluginCore Examples
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

// Manually require shared classes since docs/ is likely not in composer autoload
// Also ensure the LoggerInterface is loaded early to prevent symbol mismatch
require_once __DIR__ . '/../../../src/Log/LoggerInterface.php';
require_once __DIR__ . '/../../../src/Transaction/TransactionPersistenceInterface.php';
require_once __DIR__ . '/SimpleLogger.php';
require_once __DIR__ . '/EnvSettingsProvider.php';
require_once __DIR__ . '/FilePersistence.php';
require_once __DIR__ . '/TransactionIdLoader.php';

use WeArePlanet\PluginCore\Examples\Common\EnvSettingsProvider;
use WeArePlanet\PluginCore\Examples\Common\FilePersistence;
use WeArePlanet\PluginCore\Examples\Common\SimpleLogger;
use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Settings\Settings;

// Validate that all required environment variables are present.
$required = ['PLUGINCORE_DEMO_SPACE_ID', 'PLUGINCORE_DEMO_USER_ID', 'PLUGINCORE_DEMO_API_SECRET'];
foreach ($required as $var) {
    if (!getenv($var)) {
        fwrite(STDERR, "ERROR: Missing environment variable $var\n");
        exit(1);
    }
}

$spaceId = (int)getenv('PLUGINCORE_DEMO_SPACE_ID');

// Initialize core services and settings.
$logger = new SimpleLogger();
$settingsProvider = new EnvSettingsProvider();
$settings = new Settings($settingsProvider);
$sdkProvider = new SdkProvider($settings);

// Initialize helper components for persistence and argument loading.
// We use a local JSON file for session persistence in these examples.
$persistence = new FilePersistence('session.json');
$argLoader = new TransactionIdLoader($persistence);

// Return the initialized components as an associative array.
return [
    'sdkProvider' => $sdkProvider,
    'settings' => $settings,
    'logger' => $logger,
    'spaceId' => $spaceId,
    'persistence' => $persistence,
    'sdk_provider' => $sdkProvider,
    'settings_provider' => $settingsProvider
];
