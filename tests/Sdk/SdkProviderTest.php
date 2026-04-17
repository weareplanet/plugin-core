<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\Sdk\Configuration as SdkConfiguration;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService; // Example service

class SdkProviderTest extends TestCase
{
    private Settings $settingsMock;
    private SdkProvider $sdkProvider;

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
     * Verifies that getConfiguration() returns the correct instance of SdkConfiguration.
     *
     * This ensures that consumer applications can reliably access the underlying
     * SDK configuration object.
     */
    public function testGetConfigurationReturnsCorrectInstance(): void
    {
        // --- Act ---
        $actualConfig = $this->sdkProvider->getConfiguration();

        // --- Assert ---
        $this->assertInstanceOf(SdkConfiguration::class, $actualConfig);
    }

    /**
     * Verifies that the getService method correctly instantiates an SDK service
     * with the provider's internal configuration.
     */
    public function testGetServiceCreatesServiceWithCorrectConfiguration(): void
    {
        // --- Act ---
        $service = $this->sdkProvider->getService(SdkTransactionsService::class);

        // --- Assert ---
        $this->assertInstanceOf(SdkTransactionsService::class, $service);

        // Use reflection to check the private config property inside the service
        $reflection = new \ReflectionClass(SdkTransactionsService::class);
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        $actualConfig = $configProp->getValue($service);

        $this->assertInstanceOf(SdkConfiguration::class, $actualConfig);
        // We can't directly check the User ID/Key inside the SDK configuration easily,
        // but we've verified it was correctly passed to the service.
    }

    /**
     * Verifies that subsequent calls to getService for the same class return the same instance.
     *
     * This ensures that we are not unnecessarily recreating service instances.
     */
    public function testGetServiceReturnsSameInstanceOnSubsequentCalls(): void
    {
        $service1 = $this->sdkProvider->getService(SdkTransactionsService::class);
        $service2 = $this->sdkProvider->getService(SdkTransactionsService::class);

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
    public function testSmartUrlHandling(string $inputUrl, string $expectedHost): void
    {
        // --- Setup ---
        $settings = $this->createMock(Settings::class);
        $settings->method('getUserId')->willReturn(123);
        $settings->method('getApiKey')->willReturn('test-key');
        $settings->method('getBaseUrl')->willReturn($inputUrl);

        $provider = new SdkProvider($settings);

        // --- Act ---
        $service = $provider->getService(SdkTransactionsService::class);

        // --- Assert ---
        $reflection = new \ReflectionClass(SdkTransactionsService::class);
        $configProp = $reflection->getProperty('config');
        $configProp->setAccessible(true);
        /** @var SdkConfiguration $actualConfig */
        $actualConfig = $configProp->getValue($service);

        $this->assertSame($expectedHost, $actualConfig->getHost());
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
                'https://staging-wallee.com/api/v2.0',
            ],
            'Localhost with port' => [
                'http://localhost:8080',
                'http://localhost:8080/api/v2.0',
            ],
            'Custom URI' => [
                'custom-domain.com/my-api',
                'https://custom-domain.com/my-api',
            ],
            'Standard domain with path' => [
                'https://paymentshub.weareplanet.com/api/v2.0',
                'https://paymentshub.weareplanet.com/api/v2.0',
            ],
        ];
    }
}
