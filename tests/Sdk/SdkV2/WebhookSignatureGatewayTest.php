<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\SdkV2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\SdkV2\WebhookSignatureGateway;
use WeArePlanet\Sdk\Service\WebhookEncryptionKeysService as SdkWebhookEncryptionKeysService;

class WebhookSignatureGatewayTest extends TestCase
{
    private WebhookSignatureGateway $gateway;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkWebhookEncryptionKeysService $encryptionService;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->encryptionService = $this->createMock(SdkWebhookEncryptionKeysService::class);

        $this->sdkProvider->method('getService')
            ->with(SdkWebhookEncryptionKeysService::class)
            ->willReturn($this->encryptionService);

        $this->gateway = new WebhookSignatureGateway($this->sdkProvider, $this->logger);
    }

    public function testValidateReturnsTrueForValidSignature(): void
    {
        $header = 'valid-sig';
        $payload = 'data';

        $this->encryptionService->expects($this->once())
            ->method('isContentValid')
            ->with($header, $payload)
            ->willReturn(true);

        $this->assertTrue($this->gateway->validate($header, $payload));
    }

    public function testValidateReturnsFalseForInvalidSignature(): void
    {
        $header = 'invalid-sig';
        $payload = 'data';

        $this->encryptionService->expects($this->once())
            ->method('isContentValid')
            ->with($header, $payload)
            ->willThrowException(new \Exception("Invalid signature"));

        $this->assertFalse($this->gateway->validate($header, $payload));
    }
}
