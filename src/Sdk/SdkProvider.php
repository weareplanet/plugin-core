<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\Sdk\ApiClient;

class SdkProvider
{
    private ApiClient $apiClient;
    /** @var array<class-string, object> */
    private array $serviceInstances = [];
    private int $spaceId;

    /**
     * Initializes the SDK Provider with the given settings.
     *
     * It sets up the API Client, including authentication credentials and
     * the "Smart URL" logic for the API host.
     *
     * @param Settings $settings
     */
    public function __construct(Settings $settings)
    {
        // Use the getter methods to retrieve the values
        $this->apiClient = new ApiClient($settings->getUserId(), $settings->getApiKey());

        $baseUrl = $settings->getBaseUrl();
        if (!empty($baseUrl)) {
            $host = $baseUrl;
            // Ensure protocol is present
            if (!str_starts_with($host, 'http://') && !str_starts_with($host, 'https://')) {
                $host = 'https://' . $host;
            }
            // Smart URL Logic: Check for path (URI), append /api if missing
            $parts = parse_url($host);
            if (!isset($parts['path']) || $parts['path'] === '' || $parts['path'] === '/') {
                $host = rtrim($host, '/') . '/api';
            }
            $this->apiClient->setBasePath($host);
        }

        $this->spaceId = $settings->getSpaceId();
    }

    /**
     * Returns the API client.
     *
     * This allows consumer applications to reuse the same configured client instance
     * and avoid duplicating the host URL formatting logic.
     *
     * @return ApiClient
     */
    public function getApiClient(): ApiClient
    {
        return $this->apiClient;
    }


    /**
     * Gets or creates an instance of the requested SDK service.
     * @template T of object
     * @param class-string<T> $serviceClass
     * @return T
     */
    public function getService(string $serviceClass): object
    {
        if (!isset($this->serviceInstances[$serviceClass])) {
            if (!class_exists($serviceClass) || !method_exists($serviceClass, '__construct')) {
                throw new \InvalidArgumentException("Invalid SDK service class provided: {$serviceClass}");
            }
            $this->serviceInstances[$serviceClass] = new $serviceClass($this->apiClient);
        }
        return $this->serviceInstances[$serviceClass];
    }

    /**
     * Gets the configured Space ID.
     */
    public function getSpaceId(): int
    {
        return $this->spaceId;
    }
}
