<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\Sdk\Configuration as SdkConfiguration;
use WeArePlanet\Sdk\ApiClient as SdkApiClient;

class SdkProvider
{
    private SdkConfiguration $configuration;
    private ?SdkApiClient $apiClient = null; // Keep for backward compatibility if needed, though V2 uses Config
    private int $spaceId;
    /** @var array<class-string<object>, object> */
    private array $serviceInstances = [];

    public function __construct(Settings $settings)
    {
        // V2 uses SdkConfiguration
        $this->configuration = new SdkConfiguration($settings->getUserId(), $settings->getApiKey());
        // Fix: Set global default configuration to avoid TypeError in ObjectSerializer which relies on it
        SdkConfiguration::setDefaultConfiguration($this->configuration);
        $this->spaceId = $settings->getSpaceId();
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
