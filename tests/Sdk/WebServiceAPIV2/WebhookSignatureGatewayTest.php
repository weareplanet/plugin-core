<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\WebhookSignatureGateway;
use WeArePlanet\PluginCore\Webhook\Exception\WebhookSignatureValidationException;
use WeArePlanet\Sdk\Service\WebhookEncryptionKeysService as SdkWebhookEncryptionKeysService;

class WebhookSignatureGatewayTest extends TestCase
{
    private MockObject|SdkWebhookEncryptionKeysService $encryptionService;
    private WebhookSignatureGateway $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkProvider $sdkProvider;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->encryptionService = $this->createMock(SdkWebhookEncryptionKeysService::class);

        $this->sdkProvider->method('getService')
            ->with(SdkWebhookEncryptionKeysService::class)
            ->willReturn($this->encryptionService);

        $this->gateway = new WebhookSignatureGateway(
            $this->sdkProvider,
            $this->logger,
        );
    }

    public function testValidateReturnsFalseWhenSignatureIsInvalid(): void
    {
        $header = 'invalid-sig';
        $payload = 'data';

        $this->encryptionService->expects($this->once())
            ->method('isContentValid')
            ->with($header, $payload)
            ->willReturn(false);

        $this->assertFalse($this->gateway->validate($header, $payload));
    }

    public function testValidateReturnsTrueWhenSignatureIsValid(): void
    {
        $header = 'valid-sig';
        $payload = 'data';

        $this->encryptionService->expects($this->once())
            ->method('isContentValid')
            ->with($header, $payload)
            ->willReturn(true);

        $this->assertTrue($this->gateway->validate($header, $payload));
    }

    public function testValidateThrowsExceptionWhenCryptographicErrorOccurs(): void
    {
        $header = 'invalid-sig';
        $payload = 'data';

        $this->encryptionService->expects($this->once())
            ->method('isContentValid')
            ->with($header, $payload)
            ->willThrowException(new \Exception('Decryption error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Webhook signature validation failed'));

        $this->expectException(WebhookSignatureValidationException::class);
        $this->gateway->validate($header, $payload);
    }
}
