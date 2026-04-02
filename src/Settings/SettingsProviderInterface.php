<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Settings;

use WeArePlanet\PluginCore\LineItem\RoundingStrategy as RoundingStrategyEnum;

/**
 * Interface for providing configuration settings needed by plugin-core.
 * This must be implemented by the client plugin.
 */
interface SettingsProviderInterface
{
    public function getApiKey(): ?string;

    /**
     * Returns the API Base URL.
     * Implementations should return null to use the default WeArePlanet production URL.
     *
     * @return string|null
     */
    public function getBaseUrl(): ?string;

    public function getIntegrationMode(): IntegrationMode;

    /**
     * Should PluginCore automatically add a small adjustment line item
     * if the totals don't match? (Default: true)
     */
    public function getLineItemConsistencyEnabled(): ?bool;

    /**
     * The rounding strategy code (e.g., 'BY_LINE_ITEM' or 'BY_TOTAL').
     */
    public function getLineItemRoundingStrategy(): ?RoundingStrategyEnum;

    /**
     * Gets the configured log level (e.g., 'INFO' or 'DEBUG').
     */
    public function getLogLevel(): ?string;
    public function getSpaceId(): ?int;
    public function getUserId(): ?int;
}
