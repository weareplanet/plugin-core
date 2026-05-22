<?php

namespace WeArePlanet\PluginCore\Examples\Common;

use WeArePlanet\PluginCore\Settings\DefaultSettingsProvider;
use WeArePlanet\PluginCore\Settings\IntegrationMode;

class EnvSettingsProvider extends DefaultSettingsProvider
{
    // 1. We ONLY implement the required credentials
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

    // 2. We override ONLY what we want to change for the Demo

    public function getIntegrationMode(): IntegrationMode
    {
        $mode = getenv('PLUGINCORE_DEMO_INTEGRATION_MODE');

        return match ($mode) {
            'iframe' => IntegrationMode::IFRAME,
            'lightbox' => IntegrationMode::LIGHTBOX,
            default => IntegrationMode::PAYMENT_PAGE,
        };
    }
}
