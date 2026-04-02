<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\SdkV2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\SdkV2\WebhookManagementGateway;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener as WebhookListenerEnum;
use WeArePlanet\Sdk\Model\CreationEntityState as SdkCreationEntityState;
use WeArePlanet\Sdk\Model\WebhookListener as SdkWebhookListener;
use WeArePlanet\Sdk\Model\WebhookListenerCreate as SdkWebhookListenerCreate;
use WeArePlanet\Sdk\Model\WebhookListenerUpdate as SdkWebhookListenerUpdate;
use WeArePlanet\Sdk\Model\WebhookUrl as SdkWebhookUrl;
use WeArePlanet\Sdk\Model\WebhookUrlCreate as SdkWebhookUrlCreate;
use WeArePlanet\Sdk\Model\WebhookUrlUpdate as SdkWebhookUrlUpdate;
use WeArePlanet\Sdk\Service\WebhookListenersService as SdkWebhookListenersService;
use WeArePlanet\Sdk\Service\WebhookURLsService as SdkWebhookURLsService;

class WebhookManagementGatewayTest extends TestCase
{
    private WebhookManagementGateway $gateway;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkWebhookURLsService $urlService;
    private MockObject|SdkWebhookListenersService $listenerService;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->urlService = $this->createMock(SdkWebhookURLsService::class);
        $this->listenerService = $this->createMock(SdkWebhookListenersService::class);

        $this->sdkProvider->method('getService')
            ->willReturnMap([
                [SdkWebhookURLsService::class, $this->urlService],
                [SdkWebhookListenersService::class, $this->listenerService],
            ]);

        $this->gateway = new WebhookManagementGateway($this->sdkProvider, $this->logger);
    }

    public function testCreateUrl(): void
    {
        $spaceId = 1;
        $url = 'http://test.com';
        $name = 'Test URL';

        $sdkUrl = new SdkWebhookUrl();
        $sdkUrl->setId(100);

        // V2: postWebhooksUrls($space, $create)
        $this->urlService->expects($this->once())
            ->method('postWebhooksUrls')
            ->with($this->equalTo($spaceId), $this->callback(function (SdkWebhookUrlCreate $create) use ($url, $name) {
                return $create->getUrl() === $url &&
                    $create->getName() === $name &&
                    $create->getState() === SdkCreationEntityState::ACTIVE;
            }))
            ->willReturn($sdkUrl);

        $id = $this->gateway->createUrl($spaceId, $url, $name);

        $this->assertEquals(100, $id);
    }

    public function testCreateListener(): void
    {
        $spaceId = 1;
        $urlId = 100;
        $entityId = 1472041843695; // PaymentConnectorConfiguration
        $entityEnum = WebhookListenerEnum::PAYMENT_CONNECTOR_CONFIGURATION;
        $stateId = 'SUCCESSFUL';
        $name = 'Listener';

        $sdkListener = new SdkWebhookListener();
        $sdkListener->setId(200);

        // V2: postWebhooksListeners($space, $create)
        $this->listenerService->expects($this->once())
            ->method('postWebhooksListeners')
            ->with($this->equalTo($spaceId), $this->callback(function (SdkWebhookListenerCreate $create) use ($urlId, $entityId, $stateId, $name) {
                return $create->getUrl() === $urlId &&
                    $create->getEntity() === $entityId &&
                    $create->getEntityStates() === [$stateId] &&
                    $create->getName() === $name;
            }))
            ->willReturn($sdkListener);

        $id = $this->gateway->createListener($spaceId, $urlId, $entityEnum, [$stateId], $name);

        $this->assertEquals(200, $id);
    }

    public function testUpdateUrl(): void
    {
        $spaceId = 1;
        $id = 100;
        $newUrl = 'http://updated.com';

        $currentUrl = new SdkWebhookUrl();
        $currentUrl->setId($id);
        $currentUrl->setVersion(1);
        $currentUrl->setName('Test Webhook');
        $currentUrl->setState(SdkCreationEntityState::ACTIVE);

        // V2: getWebhooksUrlsId
        $this->urlService->expects($this->once())->method('getWebhooksUrlsId')->with($id, $spaceId)->willReturn($currentUrl);

        // V2: patchWebhooksUrlsId
        $this->urlService->expects($this->once())
            ->method('patchWebhooksUrlsId')
            ->with($this->equalTo($id), $this->equalTo($spaceId), $this->callback(function (SdkWebhookUrlUpdate $update) use ($id, $newUrl) {
                return $update->getName() === 'Test Webhook' &&
                    $update->getUrl() === $newUrl &&
                    $update->getVersion() === 1 &&
                    $update->getState() === SdkCreationEntityState::ACTIVE;
            }));

        $this->gateway->updateUrl($spaceId, $id, $newUrl);
    }

    public function testUpdateListener(): void
    {
        $spaceId = 1;
        $id = 200;
        $entityEnum = WebhookListenerEnum::PAYMENT_CONNECTOR_CONFIGURATION;
        $newState = 'FAILED';

        $currentListener = new SdkWebhookListener();
        $currentListener->setId($id);
        $currentListener->setVersion(20);

        // V2: getWebhooksListenersId
        $this->listenerService->expects($this->once())->method('getWebhooksListenersId')->with($id, $spaceId)->willReturn($currentListener);

        // V2: patchWebhooksListenersId
        $this->listenerService->expects($this->once())
            ->method('patchWebhooksListenersId')
            ->with($this->equalTo($id), $this->equalTo($spaceId), $this->callback(function (SdkWebhookListenerUpdate $update) use ($id, $newState) {
                return $update->getEntityStates() === [$newState] &&
                    $update->getVersion() === 20;
            }));

        $this->gateway->updateListener($spaceId, $id, $entityEnum, [$newState]);
    }

    public function testDeleteUrl(): void
    {
        // V2: deleteWebhooksUrlsId($id, $space)
        $this->urlService->expects($this->once())->method('deleteWebhooksUrlsId')->with(100, 1);
        $this->gateway->deleteUrl(1, 100);
    }

    public function testDeleteListener(): void
    {
        // V2: deleteWebhooksListenersId($id, $space)
        $this->listenerService->expects($this->once())->method('deleteWebhooksListenersId')->with(200, 1);
        $this->gateway->deleteListener(1, 200);
    }

    public function testGetWebhookListeners(): void
    {
        $spaceId = 1;
        $urlId = 100;

        $listener = new SdkWebhookListener();
        $listener->setId(200);
        $listener->setName('Test Listener'); // Set name to avoid TypeError

        // V2: getWebhooksListenersSearch with query "url.id:$urlId"
        $this->listenerService->expects($this->once())
            ->method('getWebhooksListenersSearch')
            ->with($spaceId, null, 100, null, null, "url.id:$urlId")
            ->willReturn([$listener]);

        $results = $this->gateway->getWebhookListeners($spaceId, $urlId);

        $this->assertCount(1, $results);
        $this->assertEquals(200, $results[0]->id); // Access property directly on DTO
    }
}
