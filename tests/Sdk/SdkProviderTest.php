<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\Sdk\ApiClient;
use WeArePlanet\Sdk\Service\TransactionService; // Example service

class SdkProviderTest extends TestCase
{
    private SdkProvider $sdkProvider;
    private Settings $settingsMock;

    /**
     * Sets up the test environment by creating a mock for the Settings and initializing the SdkProvider.
     */
    protected function setUp(): void
    {
        $this->settingsMock = $this->createMock(Settings::class);
        // Configure the settings mock to return valid credentials
        $this->settingsMock->method('getUserId')->willReturn(123);
        $this->settingsMock->method('getApiKey')->willReturn('test-key');

        $this->sdkProvider = new SdkProvider($this->settingsMock);
    }

    /**
     * Data provider for testSmartUrlHandling.
     *
     * Provides variations of input URLs and their expected normalized "Smart URL" output.
     *
     * @return array<string, array{string, string}>
     */
    public static function smartUrlDataProvider(): array
    {
        return [
            'Basic domain' => [
                'staging-wallee.com',
                'https://staging-wallee.com/api',
            ],
            'Localhost with port' => [
                'http://localhost:8080',
                'http://localhost:8080/api',
            ],
            'Custom URI' => [
                'custom-domain.com/my-api',
                'https://custom-domain.com/my-api',
            ],
            'Standard domain with path' => [
                'https://paymentshub.weareplanet.com/api',
                'https://paymentshub.weareplanet.com/api',
            ],
        ];
    }

    /**
     * Verifies that getApiClient() returns the correct instance of ApiClient.
     *
     * This ensures that consumer applications can reliably access the underlying
     * API client object.
     */
    public function testGetApiClientReturnsCorrectInstance(): void
    {
        // --- Act ---
        $actualClient = $this->sdkProvider->getApiClient();

        // --- Assert ---
        $this->assertInstanceOf(ApiClient::class, $actualClient);
    }

    /**
     * Verifies that the getService method correctly instantiates an SDK service
     * with the provider's internal API client.
     */
    public function testGetServiceCreatesServiceWithCorrectApiClient(): void
    {
        // --- Act ---
        $service = $this->sdkProvider->getService(TransactionService::class);

        // --- Assert ---
        $this->assertInstanceOf(TransactionService::class, $service);

        // Use reflection to check the private ApiClient property inside the service
        $reflection = new \ReflectionClass(TransactionService::class);
        $apiClientProp = $reflection->getProperty('apiClient');
        $apiClientProp->setAccessible(true);
        $actualApiClient = $apiClientProp->getValue($service);

        $this->assertInstanceOf(ApiClient::class, $actualApiClient);
        // We can't directly check the User ID/Key inside the SDK's ApiClient easily,
        // but we've verified it was constructed and passed.
    }

    /**
     * Verifies that subsequent calls to getService for the same class return the same instance.
     *
     * This ensures that we are not unnecessarily recreating service instances.
     */
    public function testGetServiceReturnsSameInstanceOnSubsequentCalls(): void
    {
        $service1 = $this->sdkProvider->getService(TransactionService::class);
        $service2 = $this->sdkProvider->getService(TransactionService::class);

        $this->assertSame($service1, $service2);
    }

    /**
     * Verifies that getService throws an InvalidArgumentException when provided with an invalid class name.
     */
    public function testGetServiceThrowsExceptionForInvalidClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sdkProvider->getService(\stdClass::class); // stdClass doesn't have the right constructor
    }

    /**
     * Verifies the "Smart URL" logic that automatically adds the API path if it's missing from the base URL.
     *
     * @dataProvider smartUrlDataProvider
     */
    public function testSmartUrlHandling(string $inputUrl, string $expectedBasePath): void
    {
        // --- Setup ---
        $settings = $this->createMock(Settings::class);
        $settings->method('getUserId')->willReturn(123);
        $settings->method('getApiKey')->willReturn('test-key');
        $settings->method('getBaseUrl')->willReturn($inputUrl);

        $provider = new SdkProvider($settings);

        // --- Act ---
        $apiClient = $provider->getApiClient();

        // --- Assert ---
        $this->assertSame($expectedBasePath, $apiClient->getBasePath());
    }

}
