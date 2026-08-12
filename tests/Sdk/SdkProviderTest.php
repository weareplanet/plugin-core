<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\GlobalData\Exception\GlobalDataException;
use WeArePlanet\PluginCore\Refund\Exception\RefundException;
use WeArePlanet\PluginCore\Sdk\ClientMetadata;
use WeArePlanet\PluginCore\Sdk\ClientMetadataProviderInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\Sdk\ApiClient;
use WeArePlanet\Sdk\ApiException;
use WeArePlanet\Sdk\Http\ConnectionException;
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
     * Verifies that the SDK's own default headers survive the metadata being added.
     */
    public function testClientMetadataDoesNotDisplaceTheSdksOwnDefaultHeaders(): void
    {
        $withoutMetadata = (new SdkProvider($this->settingsMock))->getApiClient()->getDefaultHeaders();

        $provider = $this->createMock(ClientMetadataProviderInterface::class);
        $provider->method('getClientMetadata')
            ->willReturn(new ClientMetadata('magento', '2.4.9', '1.2.0'));

        $withMetadata = (new SdkProvider($this->settingsMock, $provider))
            ->getApiClient()
            ->getDefaultHeaders();

        foreach ($withoutMetadata as $name => $value) {
            $this->assertArrayHasKey($name, $withMetadata);
            $this->assertSame($value, $withMetadata[$name]);
        }
    }

    /**
     * Verifies that a supplied ClientMetadataProvider registers all four
     * identification headers on the instantiated SDK client.
     */
    public function testClientMetadataHeadersAreRegisteredOnTheApiClient(): void
    {
        $provider = $this->createMock(ClientMetadataProviderInterface::class);
        $provider->method('getClientMetadata')
            ->willReturn(new ClientMetadata('magento', '2.4.9', '1.2.0'));

        $sdkProvider = new SdkProvider($this->settingsMock, $provider);
        $headers = $sdkProvider->getApiClient()->getDefaultHeaders();

        $this->assertSame('magento', $headers['x-meta-shop-system']);
        $this->assertSame('2.4.9', $headers['x-meta-shop-system-version']);
        $this->assertSame('magento-2.4', $headers['x-meta-shop-system-and-version']);
        $this->assertSame('1.2.0', $headers['x-meta-plugin-version']);
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
        $headers = $sdkProvider->getApiClient()->getDefaultHeaders();

        $this->assertArrayNotHasKey('x-meta-shop-system', $headers);
        $this->assertArrayNotHasKey('x-meta-plugin-version', $headers);
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
     * Verifies that omitting the provider entirely leaves no identification headers.
     */
    public function testNoClientMetadataProviderAddsNoHeaders(): void
    {
        $headers = (new SdkProvider($this->settingsMock))->getApiClient()->getDefaultHeaders();

        $this->assertArrayNotHasKey('x-meta-shop-system', $headers);
        $this->assertArrayNotHasKey('x-meta-shop-system-version', $headers);
        $this->assertArrayNotHasKey('x-meta-shop-system-and-version', $headers);
        $this->assertArrayNotHasKey('x-meta-plugin-version', $headers);
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

    public function testWrapExceptionAppliesTheSameClassificationToEveryDomain(): void
    {
        // The point of classifying centrally: a domain that never touches
        // withRetryable() still reports a connection failure as retryable.
        $wrapped = SdkProvider::wrapException(
            new ConnectionException(),
            GlobalDataException::class,
            'getCurrencies',
            [],
            'Reference data is temporarily unavailable.',
        );

        $this->assertTrue($wrapped->isRetryable());
    }

    public function testWrapExceptionMarksConnectionFailuresAsRetryable(): void
    {
        $wrapped = SdkProvider::wrapException(
            new ConnectionException(),
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

}
