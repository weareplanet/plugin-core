<?php

namespace MyPlugin\ExampleCheckoutImplementation;

use WeArePlanet\PluginCore\Settings\DefaultSettingsProvider;

class EnvSettingsProvider extends DefaultSettingsProvider
{
    // We ONLY implement the required credentials
    public function getSpaceId(): ?int
    {
        $val = getenv('PLUGINCORE_DEMO_SPACE_ID');
        return $val ? (int)$val : null;
    }

    public function getUserId(): ?int
    {
        $val = getenv('PLUGINCORE_DEMO_USER_ID');
        return $val ? (int)$val : null;
    }

    public function getApiKey(): ?string
    {
        $val = getenv('PLUGINCORE_DEMO_API_SECRET');
        return $val ?: null;
    }

    // We override ONLY what we want to change for the Demo
    
    // public function getLogLevel(): ?string
    // {
    //     return 'DEBUG'; // Force debug logging for the demo
    // }
}