<?php

namespace MyPlugin\ExamplePluginIdentificationImplementation;

/**
 * Plugin Identification Example
 *
 * This script demonstrates how to identify your shop system and plugin to the WeArePlanet Portal
 * by passing a ClientMetadataProviderInterface to SdkProvider. Every API call the
 * provider makes then carries the x-meta-* headers.
 *
 * USAGE:
 * php plugin_identification.php
 */

use WeArePlanet\PluginCore\Examples\Common\EnvSettingsProvider;
use WeArePlanet\PluginCore\Sdk\ClientMetadata;
use WeArePlanet\PluginCore\Sdk\ClientMetadataProviderInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Settings\Settings;

// 📖 Concept documentation: See docs/1-Getting-Started/PluginIdentification.md

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../Common/bootstrap.php';

$logger = $common['logger'];

/**
 * A real plugin reads these from its platform: the shop system's own version API,
 * and its module/extension version constant.
 */
final class ExampleClientMetadataProvider implements ClientMetadataProviderInterface
{
    public function getClientMetadata(): ?ClientMetadata
    {
        // Returning null is valid: calls then carry no identification headers.
        return new ClientMetadata(
            shopSystem: 'magento',
            shopSystemVersion: '2.4.9',
            pluginVersion: '1.2.0',
        );
    }
}

$metadata = (new ExampleClientMetadataProvider())->getClientMetadata();

echo "Headers this plugin will send on every API call:" . PHP_EOL;
foreach ($metadata->toHeaders() as $name => $value) {
    echo "  $name: $value" . PHP_EOL;
}

// Pass the provider as SdkProvider's second argument and every call carries them.
$settings = new Settings(new EnvSettingsProvider());
$sdkProvider = new SdkProvider($settings, new ExampleClientMetadataProvider());

echo PHP_EOL . "SdkProvider built with client metadata attached." . PHP_EOL;
