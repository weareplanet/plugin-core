<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Settings;

use WeArePlanet\PluginCore\LineItem\RoundingStrategy;
use WeArePlanet\PluginCore\Settings\IntegrationMode;

/**
 * Default base class for Settings Providers.
 * Provides default implementations for all optional settings.
 *
 * Integrators only need to extend this and implement the 3 abstract credential methods.
 */
abstract class DefaultSettingsProvider implements SettingsProviderInterface
{
    // --- REQUIRED: Must be implemented by the integration ---

    abstract public function getSpaceId(): ?int;
    abstract public function getUserId(): ?int;
    abstract public function getApiKey(): ?string;


    // --- OPTIONAL: Defaults provided below ---

    public function getLogLevel(): ?string
    {
        // Return null to let the Settings class default to 'INFO'
        return null;
    }

    public function getIntegrationMode(): IntegrationMode
    {
        // Default to the standard Hosted Payment Page
        return IntegrationMode::PAYMENT_PAGE;
    }

    public function getBaseUrl(): ?string
    {
        // Return null to let the Settings class use the production URL
        return null;
    }

    public function getLineItemConsistencyEnabled(): ?bool
    {
        // Return null to let the Settings class default to TRUE
        return null;
    }

    public function getLineItemRoundingStrategy(): ?RoundingStrategy
    {
        // Return null to let the Settings class default to BY_LINE_ITEM
        return null;
    }
}
