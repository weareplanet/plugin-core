<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Transaction\State as TransactionState;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;
use WeArePlanet\PluginCore\Webhook\WebhookConfig;
use WeArePlanet\PluginCore\Webhook\WebhookListener as WebhookListenerDto;
use WeArePlanet\PluginCore\Webhook\WebhookManagementGatewayInterface;
use WeArePlanet\PluginCore\Webhook\WebhookService;
use WeArePlanet\PluginCore\Webhook\WebhookSignatureGatewayInterface;

/**
 * Class WebhookServiceTest
 *
 * Tests the WebhookService logic.
 */
class WebhookServiceTest extends TestCase
{
    private LoggerInterface|MockObject $logger;
    private WebhookManagementGatewayInterface|MockObject $managementGateway;
    private WebhookService $service;
    private WebhookSignatureGatewayInterface|MockObject $signatureGateway;

    protected function setUp(): void
    {
        $this->managementGateway = $this->createMock(WebhookManagementGatewayInterface::class);
        $this->signatureGateway = $this->createMock(WebhookSignatureGatewayInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new WebhookService(
            $this->managementGateway,
            $this->signatureGateway,
            $this->logger,
        );
    }

    /**
     * Test createWebhookListener delegation.
     */
    public function testCreateWebhookListener(): void
    {
        $spaceId = 123;
        $urlId = 99;
        $entityEnum = WebhookListener::TRANSACTION;
        $eventStates = ['active'];
        $name = 'Listener';
        $expectedId = 100;

        $this->managementGateway->expects($this->once())
            ->method('createListener')
            ->with($spaceId, $urlId, $entityEnum, $eventStates, $name)
            ->willReturn($expectedId);

        $result = $this->service->createWebhookListener($spaceId, $urlId, $entityEnum, $eventStates, $name);
        $this->assertEquals($expectedId, $result);
    }

    /**
     * Test createWebhookUrl delegation.
     */
    public function testCreateWebhookUrl(): void
    {
        $spaceId = 123;
        $url = 'https://example.com/webhook';
        $name = 'Test Webhook';
        $expectedId = 99;

        $this->managementGateway->expects($this->once())
            ->method('createUrl')
            ->with($spaceId, $url, $name)
            ->willReturn($expectedId);

        $result = $this->service->createWebhookUrl($spaceId, $url, $name);
        $this->assertEquals($expectedId, $result);
    }

    /**
     * Test deleteWebhookListener delegation.
     */
    public function testDeleteWebhookListener(): void
    {
        $spaceId = 123;
        $listenerId = 100;

        $this->managementGateway->expects($this->once())
            ->method('deleteListener')
            ->with($spaceId, $listenerId);

        $this->service->deleteWebhookListener($spaceId, $listenerId);
    }

    /**
     * Test deleteWebhookUrl delegation.
     */
    public function testDeleteWebhookUrl(): void
    {
        $spaceId = 123;
        $urlId = 99;

        $this->managementGateway->expects($this->once())
            ->method('deleteUrl')
            ->with($spaceId, $urlId);

        $this->service->deleteWebhookUrl($spaceId, $urlId);
    }

    /**
     * Test cascade deletion logic in deleteWebhookUrl.
     */
    public function testDeleteWebhookUrlWithCascade(): void
    {
        $spaceId = 123;
        $urlId = 99;

        // Use proper DTOs for the return value
        $listener1 = new WebhookListenerDto(101, 'L1', 1, []);
        $listener2 = new WebhookListenerDto(102, 'L2', 1, []);

        $this->managementGateway->expects($this->once())
            ->method('getWebhookListeners')
            ->with($spaceId, $urlId)
            ->willReturn([$listener1, $listener2]);

        // Expect deleteListener to be called twice with specific IDs
        $this->managementGateway->expects($this->exactly(2))
            ->method('deleteListener')
            ->willReturnCallback(function (int $sId, int $lId) use ($spaceId): void {
                static $index = 0;
                $expectedIds = [101, 102];
                $this->assertEquals($spaceId, $sId);
                $this->assertEquals($expectedIds[$index], $lId);
                $index++;
            });

        // Expect deleteUrl to be called once
        $this->managementGateway->expects($this->once())
            ->method('deleteUrl')
            ->with($spaceId, $urlId);

        $result = $this->service->deleteWebhookUrl($spaceId, $urlId, true);
        $this->assertEquals(2, $result);
    }

    /**
     * Test getWebhookUrls delegates to gateway with provided state.
     */
    public function testGetWebhookUrls(): void
    {
        $spaceId = 123;
        $state = 'INACTIVE';
        $expectedUrls = [new \WeArePlanet\PluginCore\Webhook\WebhookUrl(1, 'Test', 'url', 1)];

        $this->managementGateway->expects($this->once())
            ->method('getWebhookUrls')
            ->with($spaceId, $state)
            ->willReturn($expectedUrls);

        $result = $this->service->getWebhookUrls($spaceId, $state);
        $this->assertSame($expectedUrls, $result);
    }

    /**
     * Test successful installation flow.
     */
    public function testInstallWebhook(): void
    {
        $spaceId = 123;
        $config = new WebhookConfig(
            'https://example.com/webhook',
            'Test Webhook',
            WebhookListener::TRANSACTION,
            [TransactionState::AUTHORIZED->value],
        );

        $this->managementGateway->expects($this->once())
            ->method('createUrl')
            ->with($spaceId, $config->url, $config->name)
            ->willReturn(99);

        // Updated expectation: pass enum object and array
        $this->managementGateway->expects($this->once())
            ->method('createListener')
            ->with($spaceId, 99, $config->entity, $config->eventStates, $config->name)
            ->willReturn(100);

        $this->logger->expects($this->atLeastOnce())
            ->method('debug');

        $this->service->installWebhook($spaceId, $config);
    }

    /**
     * Test listUrls delegates to getWebhookUrls with null state.
     */
    public function testListUrls(): void
    {
        $spaceId = 123;
        $expectedUrls = [new \WeArePlanet\PluginCore\Webhook\WebhookUrl(1, 'Test', 'url', 1)];

        $this->managementGateway->expects($this->once())
            ->method('getWebhookUrls')
            ->with($spaceId, null)
            ->willReturn($expectedUrls);

        $result = $this->service->listUrls($spaceId);
        $this->assertSame($expectedUrls, $result);
    }

    /**
     * Test successful uninstallation flow.
     */
    public function testUninstallWebhook(): void
    {
        $spaceId = 123;
        $urlId = 99;
        $listenerId = 100;

        $this->managementGateway->expects($this->once())
            ->method('deleteListener')
            ->with($spaceId, $listenerId);

        $this->managementGateway->expects($this->once())
            ->method('deleteUrl')
            ->with($spaceId, $urlId);

        $this->service->uninstallWebhook($spaceId, $urlId, $listenerId);
    }

    /**
     * Test uninstallation flow when listener deletion fails.
     */
    public function testUninstallWebhookListenerFailureStillDeletesUrl(): void
    {
        $spaceId = 123;
        $urlId = 99;
        $listenerId = 100;

        $this->managementGateway->expects($this->once())
            ->method('deleteListener')
            ->willThrowException(new \Exception("Delete listener failed"));

        $this->managementGateway->expects($this->once())
            ->method('deleteUrl')
            ->with($spaceId, $urlId);

        $this->service->uninstallWebhook($spaceId, $urlId, $listenerId);
    }

    /**
     * Test updateWebhookListener delegation.
     */
    public function testUpdateWebhookListener(): void
    {
        $spaceId = 123;
        $listenerId = 100;
        $entityEnum = WebhookListener::TRANSACTION;
        $eventStates = ['active'];

        $this->managementGateway->expects($this->once())
            ->method('updateListener')
            ->with($spaceId, $listenerId, $entityEnum, $eventStates);

        $this->service->updateWebhookListener($spaceId, $listenerId, $entityEnum, $eventStates);
    }

    /**
     * Test successful update flow.
     */
    public function testUpdateWebhookUrl(): void
    {
        $spaceId = 123;
        $urlId = 99;
        $newUrl = 'https://example.com/new-url';

        $this->managementGateway->expects($this->once())
            ->method('updateUrl')
            ->with($spaceId, $urlId, $newUrl);

        $this->service->updateWebhookUrl($spaceId, $urlId, $newUrl);
    }

    /**
     * Test signature validation failure.
     */
    public function testValidatePayloadFailure(): void
    {
        $signature = 'invalid-signature';
        $payload = '{"test": "data"}';

        $this->signatureGateway->expects($this->once())
            ->method('validate')
            ->with($signature, $payload)
            ->willReturn(false);

        $this->logger->expects($this->once())
            ->method('warning');

        $result = $this->service->validatePayload($signature, $payload);
        $this->assertFalse($result);
    }

    /**
     * Test signature validation success.
     */
    public function testValidatePayloadSuccess(): void
    {
        $signature = 'valid-signature';
        $payload = '{"test": "data"}';

        $this->signatureGateway->expects($this->once())
            ->method('validate')
            ->with($signature, $payload)
            ->willReturn(true);

        $result = $this->service->validatePayload($signature, $payload);
        $this->assertTrue($result);
    }
}
