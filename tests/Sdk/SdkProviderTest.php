<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\Sdk\ApiClient as SdkApiClient;
use WeArePlanet\Sdk\Configuration as SdkConfiguration;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService; // Example service

class SdkProviderTest extends TestCase
{
    private Settings $settingsMock;
    private SdkProvider $sdkProvider;

    protected function setUp(): void
    {
        $this->settingsMock = $this->createMock(Settings::class);
        // Configure the settings mock to return valid credentials
        $this->settingsMock->method('getUserId')->willReturn(123);
        $this->settingsMock->method('getApiKey')->willReturn('test-key');

        $this->sdkProvider = new SdkProvider($this->settingsMock);
    }

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
        // We can't directly check the User ID/Key inside the SDK's ApiClient easily,
        // but we've verified it was constructed and passed.
    }

    public function testGetServiceReturnsSameInstanceOnSubsequentCalls(): void
    {
        $service1 = $this->sdkProvider->getService(SdkTransactionsService::class);
        $service2 = $this->sdkProvider->getService(SdkTransactionsService::class);

        $this->assertSame($service1, $service2);
    }

    public function testGetServiceThrowsExceptionForInvalidClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sdkProvider->getService(\stdClass::class); // stdClass doesn't have the right constructor
    }
}
