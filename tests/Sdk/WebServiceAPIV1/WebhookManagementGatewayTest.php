<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV1;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\WebhookManagementGateway;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener as WebhookListenerEnum;
use WeArePlanet\PluginCore\Webhook\Exception\WebhookManagementException;
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

    public function testAnSdkFailureIsLoggedAndWrappedInTheDomainException(): void
    {
        // The error path was previously funnelled through a wrapSdkCall() closure and
        // had no coverage at all. It is now an explicit catch in each method, so this
        // pins that a raw SDK failure still surfaces as the domain exception with the
        // original throwable kept as previous.
        $sdkFailure = new \Exception('SDK unavailable');
        $this->listenerService->method('delete')->willThrowException($sdkFailure);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to delete the webhook listener'),
                $this->callback(function (array $context) use ($sdkFailure): bool {
                    $this->assertSame($sdkFailure, $context['exception']);
                    $this->assertSame(1, $context['spaceId']);
                    $this->assertSame(200, $context['listenerId']);

                    return true;
                }),
            );

        try {
            $this->gateway->deleteListener(1, 200);
            $this->fail('Expected a WebhookManagementException.');
        } catch (WebhookManagementException $e) {
            $this->assertSame($sdkFailure, $e->getPrevious());
            // Identifying context survives into the centralized message.
            $this->assertStringContainsString('listenerId=200', $e->getMessage());
        }
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
        $sdkListener->setName($name);
        $sdkListener->setEntity($entityId);
        $sdkListener->setEntityStates([$stateId]);

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
        $result = $this->gateway->createListener($spaceId, $urlId, $entityEnum, [$stateId], $name);

        $this->assertInstanceOf(WebhookListener::class, $result);
        $this->assertSame(200, $result->id);
        $this->assertSame($name, $result->name);
        $this->assertSame([$stateId], $result->entityStates);
    }

    public function testCreateUrl(): void
    {
        $spaceId = 1;
        $url = 'http://test.com';
        $name = 'Test URL';

        $sdkUrl = new SdkWebhookUrl();
        $sdkUrl->setId(100);
        $sdkUrl->setName($name);
        $sdkUrl->setUrl($url);
        $sdkUrl->setState(CreationEntityState::ACTIVE);

        $this->urlService->expects($this->once())
            ->method('create')
            ->with($this->equalTo($spaceId), $this->callback(function (SdkWebhookUrlCreate $create) use ($url, $name) {
                return $create->getUrl() === $url &&
                    $create->getName() === $name &&
                    $create->getState() === CreationEntityState::ACTIVE;
            }))
            ->willReturn($sdkUrl);

        $result = $this->gateway->createUrl($spaceId, $url, $name);

        $this->assertInstanceOf(WebhookUrl::class, $result);
        $this->assertSame(100, $result->id);
        $this->assertSame($name, $result->name);
        $this->assertSame($url, $result->url);
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

        $results = $this->gateway->getWebhookListeners($spaceId, $urlId, );

        $this->assertCount(1, $results, );
        // Assert the returned object is a domain DTO, not an SDK object
        $this->assertInstanceOf(WebhookListener::class, $results->first(), );
        $this->assertEquals(200, $results->first()->id, );
        $this->assertEquals('Test Listener', $results->first()->name, );
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

        $results = $this->gateway->getWebhookUrls($spaceId, $state, );

        $this->assertCount(1, $results, );
        $this->assertInstanceOf(WebhookUrl::class, $results->first(), );
        $this->assertEquals(100, $results->first()->id, );
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

        $results = $this->gateway->listUrls($spaceId, );

        $this->assertCount(1, $results, );
        $this->assertInstanceOf(WebhookUrl::class, $results->first(), );
        $this->assertEquals(100, $results->first()->id, );
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

        $updatedListener = new SdkWebhookListener();
        $updatedListener->setId($id);
        $updatedListener->setName('Updated Listener');
        $updatedListener->setEntity($entityEnum->value);
        $updatedListener->setEntityStates([$newState]);

        $this->listenerService->expects($this->once())->method('read')->with($spaceId, $id)->willReturn($currentListener);

        $this->listenerService->expects($this->once())
            ->method('update')
            ->with($this->equalTo($spaceId), $this->callback(function (WebhookListenerUpdate $update) use ($id, $newState) {
                return $update->getId() === $id &&
                    $update->getEntityStates() === [$newState] &&
                    $update->getVersion() === 20;
            }))
            ->willReturn($updatedListener);

        // Call with enum + array (new interface signature)
        $result = $this->gateway->updateListener($spaceId, $id, $entityEnum, [$newState]);

        $this->assertInstanceOf(WebhookListener::class, $result);
        $this->assertSame($id, $result->id);
        $this->assertSame([$newState], $result->entityStates);
    }

    public function testUpdateUrl(): void
    {
        $spaceId = 1;
        $id = 100;
        $newUrl = 'http://updated.com';

        $currentUrl = new SdkWebhookUrl();
        $currentUrl->setId($id);
        $currentUrl->setVersion(10);

        $updatedUrl = new SdkWebhookUrl();
        $updatedUrl->setId($id);
        $updatedUrl->setName('Updated URL');
        $updatedUrl->setUrl($newUrl);
        $updatedUrl->setState(CreationEntityState::ACTIVE);

        $this->urlService->expects($this->once())->method('read')->with($spaceId, $id)->willReturn($currentUrl);

        $this->urlService->expects($this->once())
            ->method('update')
            ->with($this->equalTo($spaceId), $this->callback(function (WebhookUrlUpdate $update) use ($id, $newUrl) {
                return $update->getId() === $id &&
                    $update->getUrl() === $newUrl &&
                    $update->getVersion() === 10;
            }))
            ->willReturn($updatedUrl);

        $result = $this->gateway->updateUrl($spaceId, $id, $newUrl);

        $this->assertInstanceOf(WebhookUrl::class, $result);
        $this->assertSame($id, $result->id);
        $this->assertSame($newUrl, $result->url);
    }
}
