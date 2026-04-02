<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Transaction\State as TransactionState;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;
use WeArePlanet\PluginCore\Webhook\WebhookConfig;
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
    private MockObject|WebhookManagementGatewayInterface $managementGateway;
    private MockObject|WebhookSignatureGatewayInterface $signatureGateway;
    private MockObject|LoggerInterface $logger;
    private WebhookService $service;

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
     * Test successful installation flow.
     */
    public function testInstallWebhook(): void
    {
        $spaceId = 123;
        $config = new WebhookConfig(
            'https://example.com/webhook',
            'Test Webhook',
            WebhookListener::TRANSACTION->value,
            TransactionState::AUTHORIZED->value,
        );

        $this->managementGateway->expects($this->once())
            ->method('createUrl')
            ->with($spaceId, $config->url, $config->name)
            ->willReturn(99);

        // Expect Enum and Array state
        $this->managementGateway->expects($this->once())
            ->method('createListener')
            ->with($spaceId, 99, WebhookListener::TRANSACTION, [$config->eventStateId], $config->name)
            ->willReturn(100);

        // Expect getUrl call
        $expectedUrl = new \WeArePlanet\PluginCore\Webhook\WebhookUrl(99, $config->name, $config->url, 1);
        $this->managementGateway->expects($this->once())
            ->method('getUrl')
            ->with($spaceId, 99)
            ->willReturn($expectedUrl);

        $this->logger->expects($this->atLeastOnce())
            ->method('debug');

        $result = $this->service->installWebhook($spaceId, $config);
        $this->assertSame($expectedUrl, $result);
    }

    /**
     * Test successful uninstallation flow.
     */
    public function testUninstallWebhook(): void
    {
        $spaceId = 123;
        $urlId = 99;
        $entityId = WebhookListener::TRANSACTION->value;
        $stateId = 'active';
        $listenerId = 100;

        // Mock getting listeners to find ID using DTO
        $listenerDTO = new \WeArePlanet\PluginCore\Webhook\WebhookListener(
            $listenerId,
            'Test Listener',
            $entityId,
            [$stateId],
        );

        $this->managementGateway->expects($this->once())
            ->method('getWebhookListeners')
            ->with($spaceId, $urlId)
            ->willReturn([$listenerDTO]);

        $this->managementGateway->expects($this->once())
            ->method('deleteListener')
            ->with($spaceId, $listenerId);

        $this->managementGateway->expects($this->once())
            ->method('deleteUrl')
            ->with($spaceId, $urlId);

        $this->service->uninstallWebhook($spaceId, $urlId, WebhookListener::TRANSACTION, $stateId);
    }

    /**
     * Test uninstallation flow when listener deletion fails.
     */
    public function testUninstallWebhookListenerFailureStillDeletesUrl(): void
    {
        $spaceId = 123;
        $urlId = 99;
        // $listenerId = 100; // Resolved dynamically now
        $entityId = WebhookListener::TRANSACTION->value;
        $stateId = 'active';
        $listenerId = 100;

        $listenerDTO = new \WeArePlanet\PluginCore\Webhook\WebhookListener(
            $listenerId,
            'Test Listener',
            $entityId,
            [$stateId],
        );

        $this->managementGateway->expects($this->once())
            ->method('getWebhookListeners')
            ->with($spaceId, $urlId)
            ->willReturn([$listenerDTO]);

        $this->managementGateway->expects($this->once())
            ->method('deleteListener')
            ->willThrowException(new \Exception("Delete listener failed"));

        $this->managementGateway->expects($this->once())
            ->method('deleteUrl')
            ->with($spaceId, $urlId);

        $this->service->uninstallWebhook($spaceId, $urlId, WebhookListener::TRANSACTION, $stateId);
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
     * Test createWebhookListener delegation.
     */
    public function testCreateWebhookListener(): void
    {
        $spaceId = 123;
        $urlId = 99;
        $entityEnum = \WeArePlanet\PluginCore\Webhook\Enum\WebhookListener::TRANSACTION;
        $stateId = 'active';
        $name = 'Listener';
        $expectedId = 100;

        $this->managementGateway->expects($this->once())
            ->method('createListener')
            ->with($spaceId, $urlId, $entityEnum, [$stateId], $name)
            ->willReturn($expectedId);

        $result = $this->service->createWebhookListener($spaceId, $urlId, $entityEnum, [$stateId], $name);
        $this->assertEquals($expectedId, $result);
    }

    /**
     * Test updateWebhookListener delegation.
     */
    public function testUpdateWebhookListener(): void
    {
        $spaceId = 123;
        $listenerId = 100;
        $entityEnum = \WeArePlanet\PluginCore\Webhook\Enum\WebhookListener::TRANSACTION;
        $stateId = 'active';

        $this->managementGateway->expects($this->once())
            ->method('updateListener')
            ->with($spaceId, $listenerId, $entityEnum, [$stateId]);

        $this->service->updateWebhookListener($spaceId, $listenerId, $entityEnum, $stateId);
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
     * Test cascade deletion logic in deleteWebhookUrl.
     */
    public function testDeleteWebhookUrlWithCascade(): void
    {
        $spaceId = 123;
        $urlId = 99;

        // Use DTOs with guaranteed IDs
        $listener1 = new \WeArePlanet\PluginCore\Webhook\WebhookListener(101, 'L1', 1, []);
        $listener2 = new \WeArePlanet\PluginCore\Webhook\WebhookListener(102, 'L2', 1, []);

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
}
