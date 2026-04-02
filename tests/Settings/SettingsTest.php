<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Settings;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Settings\SettingsProviderInterface;

class SettingsTest extends TestCase
{
    private SettingsProviderInterface $providerMock;
    private Settings $settings;

    protected function setUp(): void
    {
        $this->providerMock = $this->createMock(SettingsProviderInterface::class);
        $this->settings = new Settings($this->providerMock);
    }

    public function testGetApiKeyReturnsValueFromProvider(): void
    {
        $expectedKey = 'test-api-key';
        $this->providerMock->method('getApiKey')->willReturn($expectedKey);
        $this->assertSame($expectedKey, $this->settings->getApiKey());
    }

    public function testGetApiKeyThrowsExceptionWhenProviderReturnsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->providerMock->method('getApiKey')->willReturn('');
        $this->settings->getApiKey();
    }

    public function testGetApiKeyThrowsExceptionWhenProviderReturnsNull(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->providerMock->method('getApiKey')->willReturn(null);
        $this->settings->getApiKey();
    }

    public function testGetSpaceIdReturnsValueFromProvider(): void
    {
        $expectedId = 12345;
        $this->providerMock->method('getSpaceId')->willReturn($expectedId);
        $this->assertSame($expectedId, $this->settings->getSpaceId());
    }

    public function testGetSpaceIdThrowsExceptionWhenProviderReturnsNull(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->providerMock->method('getSpaceId')->willReturn(null);
        $this->settings->getSpaceId();
    }

    public function testGetSpaceIdThrowsExceptionWhenProviderReturnsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->providerMock->method('getSpaceId')->willReturn(0);
        $this->settings->getSpaceId();
    }

    public function testGetUserIdReturnsValueFromProvider(): void
    {
        $expectedId = 67890;
        $this->providerMock->method('getUserId')->willReturn($expectedId);
        $this->assertSame($expectedId, $this->settings->getUserId());
    }

    public function testGetUserIdThrowsExceptionWhenProviderReturnsNull(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->providerMock->method('getUserId')->willReturn(null);
        $this->settings->getUserId();
    }
}
