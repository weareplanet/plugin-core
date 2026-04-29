<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV1;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\WebhookManagementGateway;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener as WebhookListenerEnum;
use WeArePlanet\PluginCore\Webhook\WebhookListener;
use WeArePlanet\PluginCore\Webhook\WebhookUrl;
use WeArePlanet\Sdk\Model\CreationEntityState;
use WeArePlanet\Sdk\Model\EntityQueryFilter;
use WeArePlanet\Sdk\Model\WebhookListener as SdkWebhookListener;
use WeArePlanet\Sdk\Model\WebhookListenerCreate as SdkWebhookListenerCreate;
use WeArePlanet\Sdk\Model\WebhookListenerUpdate;
use WeArePlanet\Sdk\Model\WebhookUrl as SdkWebhookUrl;
use WeArePlanet\Sdk\Model\WebhookUrlCreate as SdkWebhookUrlCreate;
use WeArePlanet\Sdk\Model\WebhookUrlUpdate;
use WeArePlanet\Sdk\Service\WebhookListenerService as SdkWebhookListenerService;
use WeArePlanet\Sdk\Service\WebhookUrlService as SdkWebhookUrlService;

class WebhookManagementGatewayTest extends TestCase
{
    private WebhookManagementGateway $gateway;
    private MockObject|SdkWebhookListenerService $listenerService;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkWebhookUrlService $urlService;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->urlService = $this->createMock(SdkWebhookUrlService::class);
        $this->listenerService = $this->createMock(SdkWebhookListenerService::class);

        $this->sdkProvider->method('getService')
            ->willReturnMap([
                [SdkWebhookUrlService::class, $this->urlService],
                [SdkWebhookListenerService::class, $this->listenerService],
            ]);

        $this->gateway = new WebhookManagementGateway($this->sdkProvider, $this->logger);
    }

    /**
     * Tests that createListener accepts WebhookListenerEnum and array of states,
     * and correctly maps the enum's int value to SDK v1's setEntity().
     */
    public function testCreateListener(): void
    {
        $spaceId = 1;
        $urlId = 100;
        // Use the enum — its backing int value is the entity ID for SDK v1
        $entityEnum = WebhookListenerEnum::PAYMENT_CONNECTOR_CONFIGURATION;
        $entityId = $entityEnum->value; // 1472041843695
        $stateId = 'SUCCESSFUL';
        $name = 'Listener';

        $sdkListener = new SdkWebhookListener();
        $sdkListener->setId(200);

        $this->listenerService->expects($this->once())
            ->method('create')
            ->with($this->equalTo($spaceId), $this->callback(function (SdkWebhookListenerCreate $create) use ($urlId, $entityId, $stateId, $name) {
                return $create->getUrl() === $urlId &&
                    $create->getEntity() === $entityId &&
                    $create->getEntityStates() === [$stateId] &&
                    $create->getName() === $name;
            }))
            ->willReturn($sdkListener);

        // Call with enum + array (new interface signature)
        $id = $this->gateway->createListener($spaceId, $urlId, $entityEnum, [$stateId], $name);

        $this->assertEquals(200, $id);
    }

    public function testCreateUrl(): void
    {
        $spaceId = 1;
        $url = 'http://test.com';
        $name = 'Test URL';

        $sdkUrl = new SdkWebhookUrl();
        $sdkUrl->setId(100);

        $this->urlService->expects($this->once())
            ->method('create')
            ->with($this->equalTo($spaceId), $this->callback(function (SdkWebhookUrlCreate $create) use ($url, $name) {
                return $create->getUrl() === $url &&
                    $create->getName() === $name &&
                    $create->getState() === CreationEntityState::ACTIVE;
            }))
            ->willReturn($sdkUrl);

        $id = $this->gateway->createUrl($spaceId, $url, $name);

        $this->assertEquals(100, $id);
    }

    public function testDeleteListener(): void
    {
        $this->listenerService->expects($this->once())->method('delete')->with(1, 200);
        $this->gateway->deleteListener(1, 200);
    }

    public function testDeleteUrl(): void
    {
        $this->urlService->expects($this->once())->method('delete')->with(1, 100);
        $this->gateway->deleteUrl(1, 100);
    }

    /**
     * Tests that getUrl reads a single webhook URL from SDK v1
     * and returns a typed WebhookUrl DTO.
     */
    public function testGetUrl(): void
    {
        $spaceId = 1;
        $webhookUrlId = 100;

        $sdkUrl = new SdkWebhookUrl();
        $sdkUrl->setId($webhookUrlId);
        $sdkUrl->setName('Test URL');
        $sdkUrl->setUrl('http://test.com');
        $sdkUrl->setState(CreationEntityState::ACTIVE);

        $this->urlService->expects($this->once())
            ->method('read')
            ->with($spaceId, $webhookUrlId)
            ->willReturn($sdkUrl);

        $result = $this->gateway->getUrl($spaceId, $webhookUrlId);

        // Assert the returned object is a domain DTO, not an SDK object
        $this->assertInstanceOf(WebhookUrl::class, $result);
        $this->assertEquals($webhookUrlId, $result->id);
        $this->assertEquals('Test URL', $result->name);
        $this->assertEquals('http://test.com', $result->url);
    }

    /**
     * Tests that getWebhookListeners returns typed WebhookListener DTOs
     * instead of raw SDK objects.
     */
    public function testGetWebhookListeners(): void
    {
        $spaceId = 1;
        $urlId = 100;

        $listener = new SdkWebhookListener();
        $listener->setId(200);
        $listener->setName('Test Listener');

        $this->listenerService->expects($this->once())
            ->method('search')
            ->with($this->equalTo($spaceId), $this->callback(function ($query) use ($urlId) {
                $filter = $query->getFilter();
                return $filter instanceof EntityQueryFilter &&
                    $filter->getFieldName() === 'url.id' &&
                    $filter->getValue() === $urlId &&
                    $query->getNumberOfEntities() === 100;
            }))
            ->willReturn([$listener]);

        $results = $this->gateway->getWebhookListeners($spaceId, $urlId);

        $this->assertCount(1, $results);
        // Assert the returned object is a domain DTO, not an SDK object
        $this->assertInstanceOf(WebhookListener::class, $results[0]);
        $this->assertEquals(200, $results[0]->id);
        $this->assertEquals('Test Listener', $results[0]->name);
    }

    /**
     * Tests that getWebhookUrls applies the specified state filter.
     */
    public function testGetWebhookUrlsWithStateFilter(): void
    {
        $spaceId = 1;
        $state = 'ACTIVE';
        $sdkUrl = new SdkWebhookUrl();
        $sdkUrl->setId(100);

        $this->urlService->expects($this->once())
            ->method('search')
            ->with($this->equalTo($spaceId), $this->callback(function ($query) use ($state) {
                $filter = $query->getFilter();
                return $filter instanceof EntityQueryFilter &&
                    $filter->getFieldName() === 'state' &&
                    $filter->getValue() === $state;
            }))
            ->willReturn([$sdkUrl]);

        $results = $this->gateway->getWebhookUrls($spaceId, $state);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(WebhookUrl::class, $results[0]);
        $this->assertEquals(100, $results[0]->id);
    }

    /**
     * Tests that listUrls does not apply a state filter.
     */
    public function testListUrls(): void
    {
        $spaceId = 1;
        $sdkUrl = new SdkWebhookUrl();
        $sdkUrl->setId(100);

        $this->urlService->expects($this->once())
            ->method('search')
            ->with($this->equalTo($spaceId), $this->callback(function ($query) {
                return $query->getFilter() === null;
            }))
            ->willReturn([$sdkUrl]);

        $results = $this->gateway->listUrls($spaceId);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(WebhookUrl::class, $results[0]);
        $this->assertEquals(100, $results[0]->id);
    }

    /**
     * Tests that updateListener accepts WebhookListenerEnum and array of states,
     * and correctly passes the event states to SDK v1.
     */
    public function testUpdateListener(): void
    {
        $spaceId = 1;
        $id = 200;
        // Use the enum — SDK v1 update does not change the entity, but the
        // interface still requires it for consistency with v2
        $entityEnum = WebhookListenerEnum::PAYMENT_CONNECTOR_CONFIGURATION;
        $newState = 'FAILED';

        $currentListener = new SdkWebhookListener();
        $currentListener->setId($id);
        $currentListener->setVersion(20);

        $this->listenerService->expects($this->once())->method('read')->with($spaceId, $id)->willReturn($currentListener);

        $this->listenerService->expects($this->once())
            ->method('update')
            ->with($this->equalTo($spaceId), $this->callback(function (WebhookListenerUpdate $update) use ($id, $newState) {
                return $update->getId() === $id &&
                    $update->getEntityStates() === [$newState] &&
                    $update->getVersion() === 20;
            }));

        // Call with enum + array (new interface signature)
        $this->gateway->updateListener($spaceId, $id, $entityEnum, [$newState]);
    }

    public function testUpdateUrl(): void
    {
        $spaceId = 1;
        $id = 100;
        $newUrl = 'http://updated.com';

        $currentUrl = new SdkWebhookUrl();
        $currentUrl->setId($id);
        $currentUrl->setVersion(10);

        $this->urlService->expects($this->once())->method('read')->with($spaceId, $id)->willReturn($currentUrl);

        $this->urlService->expects($this->once())
            ->method('update')
            ->with($this->equalTo($spaceId), $this->callback(function (WebhookUrlUpdate $update) use ($id, $newUrl) {
                return $update->getId() === $id &&
                    $update->getUrl() === $newUrl &&
                    $update->getVersion() === 10;
            }));

        $this->gateway->updateUrl($spaceId, $id, $newUrl);
    }
}
