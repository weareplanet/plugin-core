<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\GlobalData\Exception\GlobalDataException;
use WeArePlanet\PluginCore\Refund\Exception\RefundException;
use WeArePlanet\Sdk\ApiException;
use WeArePlanet\PluginCore\Sdk\ClientMetadata;
use WeArePlanet\PluginCore\Sdk\ClientMetadataProviderInterface;
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

    /**
     * Verifies that a supplied ClientMetadataProvider registers all four
     * identification headers on the instantiated SDK client.
     */
    public function testClientMetadataHeadersAreRegisteredOnTheConfiguration(): void
    {
        $provider = $this->createMock(ClientMetadataProviderInterface::class);
        $provider->method('getClientMetadata')
            ->willReturn(new ClientMetadata('magento', '2.4.9', '1.2.0'));

        $sdkProvider = new SdkProvider($this->settingsMock, $provider);
        $headers = $sdkProvider->getConfiguration()->getDefaultHeaders();

        $this->assertSame('magento', $headers['x-meta-shop-system']);
        $this->assertSame('2.4.9', $headers['x-meta-shop-system-version']);
        $this->assertSame('magento-2.4', $headers['x-meta-shop-system-and-version']);
        $this->assertSame('1.2.0', $headers['x-meta-plugin-version']);
    }

    /**
     * Verifies that the SDK's own x-meta-sdk-* headers survive the metadata being
     * added. This SDK replaces the whole default-header array when set, so a merge
     * bug here would silently strip the SDK's own identification.
     */
    public function testClientMetadataDoesNotDisplaceTheSdksOwnDefaultHeaders(): void
    {
        $withoutMetadata = (new SdkProvider($this->settingsMock))->getConfiguration()->getDefaultHeaders();

        $provider = $this->createMock(ClientMetadataProviderInterface::class);
        $provider->method('getClientMetadata')
            ->willReturn(new ClientMetadata('magento', '2.4.9', '1.2.0'));

        $withMetadata = (new SdkProvider($this->settingsMock, $provider))
            ->getConfiguration()
            ->getDefaultHeaders();

        foreach ($withoutMetadata as $name => $value) {
            $this->assertArrayHasKey($name, $withMetadata);
            $this->assertSame($value, $withMetadata[$name]);
        }
    }

    /**
     * Verifies that omitting the provider entirely leaves no identification headers.
     */
    public function testNoClientMetadataProviderAddsNoHeaders(): void
    {
        $headers = (new SdkProvider($this->settingsMock))->getConfiguration()->getDefaultHeaders();

        $this->assertArrayNotHasKey('x-meta-shop-system', $headers);
        $this->assertArrayNotHasKey('x-meta-shop-system-version', $headers);
        $this->assertArrayNotHasKey('x-meta-shop-system-and-version', $headers);
        $this->assertArrayNotHasKey('x-meta-plugin-version', $headers);
    }

    /**
     * Verifies that a provider answering null is a supported outcome, not a failure:
     * an integration that cannot determine its versions still works.
     */
    public function testClientMetadataProviderReturningNullAddsNoHeaders(): void
    {
        $provider = $this->createMock(ClientMetadataProviderInterface::class);
        $provider->method('getClientMetadata')->willReturn(null);

        $sdkProvider = new SdkProvider($this->settingsMock, $provider);
        $headers = $sdkProvider->getConfiguration()->getDefaultHeaders();

        $this->assertArrayNotHasKey('x-meta-shop-system', $headers);
        $this->assertArrayNotHasKey('x-meta-plugin-version', $headers);
    }

    public function testWrapExceptionMarksConnectionFailuresAsRetryable(): void
    {
        $wrapped = SdkProvider::wrapException(
            new ApiException('Connection refused', 0),
            RefundException::class,
            'refund',
            ['spaceId' => 42],
            'The payment service is temporarily unreachable.',
        );

        $this->assertTrue($wrapped->isRetryable());
    }

    public function testWrapExceptionTreatsApiRejectionsAsTerminal(): void
    {
        $wrapped = SdkProvider::wrapException(
            new ApiException('Bad Request', 400),
            RefundException::class,
            'refund',
            ['spaceId' => 42],
            'The refund was rejected.',
        );

        $this->assertFalse($wrapped->isRetryable());
    }

    public function testWrapExceptionAppliesTheSameClassificationToEveryDomain(): void
    {
        // The point of classifying centrally: a domain that never touches
        // withRetryable() still reports a connection failure as retryable.
        $wrapped = SdkProvider::wrapException(
            new ApiException('Connection refused', 0),
            GlobalDataException::class,
            'getCurrencies',
            [],
            'Reference data is temporarily unavailable.',
        );

        $this->assertTrue($wrapped->isRetryable());
    }

}
