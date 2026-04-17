<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\Sdk\Configuration as SdkConfiguration;

class SdkProvider
{
    private SdkConfiguration $configuration;
    private int $spaceId;
    /** @var array<class-string<object>, object> */
    private array $serviceInstances = [];

    /**
     * Initializes the SDK Provider with the given settings.
     *
     * It sets up the SDK Configuration, including authentication credentials and
     * the "Smart URL" logic for the API host.
     *
     * @param Settings $settings
     */
    public function __construct(Settings $settings)
    {
        // V2 uses SdkConfiguration
        $this->configuration = new SdkConfiguration($settings->getUserId(), $settings->getApiKey());

        $baseUrl = $settings->getBaseUrl();
        if (!empty($baseUrl)) {
            $host = $baseUrl;
            // Ensure protocol is present
            if (!str_starts_with($host, 'http://') && !str_starts_with($host, 'https://')) {
                $host = 'https://' . $host;
            }
            // Smart URL Logic: Check for path (URI), append /api/v2.0 if missing
            $parts = parse_url($host);
            if (!isset($parts['path']) || $parts['path'] === '' || $parts['path'] === '/') {
                $host = rtrim($host, '/') . '/api/v2.0';
            }
            $this->configuration->setHost($host);
        }

        // Set global default configuration to avoid TypeError in ObjectSerializer which relies on it
        SdkConfiguration::setDefaultConfiguration($this->configuration);
        $this->spaceId = $settings->getSpaceId();
    }

    /**
     * Returns the SDK configuration.
     *
     * This allows consumer applications to reuse the same configured SDK instance
     * and avoid duplicating the host URL formatting logic.
     *
     * @return SdkConfiguration
     */
    public function getConfiguration(): SdkConfiguration
    {
        return $this->configuration;
    }

    /**
     * Gets or creates an instance of the requested SDK service.
     * @param class-string<T> $serviceClassName
     * @return T
     * @template T of object
     */
    public function getService(string $serviceClassName): object
    {
        if (!isset($this->serviceInstances[$serviceClassName])) {
            if (!class_exists($serviceClassName) || !method_exists($serviceClassName, '__construct')) {
                throw new \InvalidArgumentException("Invalid SDK service class provided: {$serviceClassName}");
            }
            // V2 Services take SdkConfiguration as first argument
            $this->serviceInstances[$serviceClassName] = new $serviceClassName($this->configuration);
        }
        return $this->serviceInstances[$serviceClassName];
    }

    /**
     * Gets the configured Space ID.
     */
    public function getSpaceId(): int
    {
        return $this->spaceId;
    }
}
