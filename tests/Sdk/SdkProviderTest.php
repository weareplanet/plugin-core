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

    protected function setUp(): void
    {
        $this->settingsMock = $this->createMock(Settings::class);
        // Configure the settings mock to return valid credentials
        $this->settingsMock->method('getUserId')->willReturn(123);
        $this->settingsMock->method('getApiKey')->willReturn('test-key');

        $this->sdkProvider = new SdkProvider($this->settingsMock);
    }

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

    public function testGetServiceReturnsSameInstanceOnSubsequentCalls(): void
    {
        $service1 = $this->sdkProvider->getService(TransactionService::class);
        $service2 = $this->sdkProvider->getService(TransactionService::class);

        $this->assertSame($service1, $service2);
    }

    public function testGetServiceThrowsExceptionForInvalidClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sdkProvider->getService(\stdClass::class); // stdClass doesn't have the right constructor
    }
}
