<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Settings;

use WeArePlanet\PluginCore\LineItem\RoundingStrategy as RoundingStrategyEnum;

/**
 * Provides access to validated configuration settings.
 * It uses a SettingsProviderInterface to fetch the raw values.
 */
class Settings
{
    // Define our own dependency-free constants for log levels
    public const LOG_LEVEL_DEBUG = 'DEBUG';
    public const LOG_LEVEL_INFO = 'INFO';

    // Define the numeric string value for Monolog's DEBUG level
    private const MONOLOG_DEBUG_LEVEL = '100';

    public function __construct(
        private readonly SettingsProviderInterface $provider,
    ) {
    }

    public function getApiKey(): string
    {
        $value = $this->provider->getApiKey();
        if (empty($value)) {
            throw new \InvalidArgumentException('WeArePlanet API Key is missing or invalid.');
        }
        return (string) $value;
    }

    /**
     * Returns the API Base URL.
     * Can be overridden via database setting 'base_url' for Staging/Testing.
     */
    public function getBaseUrl(): string
    {
        // Try fetching a custom Base URL from the provider.
        $customUrl = $this->provider->getBaseUrl();

        if (!empty($customUrl) && is_string($customUrl)) {
            return rtrim($customUrl, '/');
        }

        // Default to the production environment if no custom URL is provided.
        return 'https://app-wallee.com';
    }

    public function getIntegrationMode(): IntegrationMode
    {
        // Fallback is also PAYMENT_PAGE to be double safe
        return $this->provider->getIntegrationMode() ?? IntegrationMode::PAYMENT_PAGE;
    }

    /**
     * Gets the configured rounding strategy.
     * Defaults to RoundingStrategyEnum::BY_LINE_ITEM if missing.
     */
    public function getLineItemRoundingStrategy(): RoundingStrategyEnum
    {
        // The provider returns ?RoundingStrategyEnum, so we just check for null
        return $this->provider->getLineItemRoundingStrategy() ?? RoundingStrategyEnum::BY_LINE_ITEM;
    }

    /**
     * Gets the configured log level string ('INFO' or 'DEBUG').
     * Defaults to 'INFO' if not provided or not 'DEBUG'.
     */
    public function getLogLevel(): string
    {
        $level = (string) $this->provider->getLogLevel();

        // Check for either the string 'DEBUG' (case-insensitive)
        // or the Monolog numeric string '100'.
        if (strtoupper($level) === self::LOG_LEVEL_DEBUG || $level === self::MONOLOG_DEBUG_LEVEL) {
            return self::LOG_LEVEL_DEBUG;
        }

        // Default to INFO for all other cases (e.g., 'INFO', '200', null, empty)
        return self::LOG_LEVEL_INFO;
    }

    public function getSpaceId(): int
    {
        $value = $this->provider->getSpaceId();
        if (empty($value)) {
            throw new \InvalidArgumentException('WeArePlanet Space ID is missing or invalid.');
        }
        return (int) $value;
    }

    public function getUserId(): int
    {
        $value = $this->provider->getUserId();
        if (empty($value)) {
            throw new \InvalidArgumentException('WeArePlanet User ID is missing or invalid.');
        }
        return (int) $value;
    }

    /**
     * Checks if line item consistency (auto-correction) is enabled.
     * Defaults to TRUE if not explicitly disabled.
     */
    public function isLineItemConsistencyEnabled(): bool
    {
        // Default to true if null
        return $this->provider->getLineItemConsistencyEnabled() ?? true;
    }
}
