<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Settings;

use WeArePlanet\PluginCore\LineItem\RoundingStrategy as RoundingStrategyEnum;
use WeArePlanet\PluginCore\Settings\IntegrationMode as IntegrationModeEnum;

/**
 * Interface for providing configuration settings needed by plugin-core.
 * This must be implemented by the client plugin.
 */
interface SettingsProviderInterface
{
    public function getSpaceId(): ?int;
    public function getUserId(): ?int;
    public function getApiKey(): ?string;

    /**
     * Gets the configured log level (e.g., 'INFO' or 'DEBUG').
     */
    public function getLogLevel(): ?string;

    /**
     * Should PluginCore automatically add a small adjustment line item
     * if the totals don't match? (Default: true)
     */
    public function getLineItemConsistencyEnabled(): ?bool;

    /**
     * The rounding strategy code (e.g., 'BY_LINE_ITEM' or 'BY_TOTAL').
     */
    public function getLineItemRoundingStrategy(): ?RoundingStrategyEnum;

    public function getIntegrationMode(): IntegrationModeEnum;

    /**
     * Returns the API Base URL.
     * Implementations should return null to use the default WeArePlanet production URL.
     *
     * @return string|null
     */
    public function getBaseUrl(): ?string;
}
