<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener as WebhookListenerEnum;
use WeArePlanet\PluginCore\Webhook\WebhookListener;
use WeArePlanet\PluginCore\Webhook\WebhookManagementGatewayInterface;
use WeArePlanet\PluginCore\Webhook\WebhookUrl;
use WeArePlanet\Sdk\Model\CreationEntityState as SdkCreationEntityState;
use WeArePlanet\Sdk\Model\WebhookListenerCreate as SdkWebhookListenerCreate;
use WeArePlanet\Sdk\Model\WebhookListenerUpdate as SdkWebhookListenerUpdate;
use WeArePlanet\Sdk\Model\WebhookUrlCreate as SdkWebhookUrlCreate;
use WeArePlanet\Sdk\Model\WebhookUrlUpdate as SdkWebhookUrlUpdate;
use WeArePlanet\Sdk\Service\WebhookListenersService as SdkWebhookListenersService;
use WeArePlanet\Sdk\Service\WebhookURLsService as SdkWebhookURLsService;

/**
 * Class WebhookManagementGateway
 *
 * Implementation of the WebhookManagementGatewayInterface using the WeArePlanet SDK V2.
 */
class WebhookManagementGateway implements WebhookManagementGatewayInterface
{
    private SdkWebhookURLsService $webhookUrlService;
    private SdkWebhookListenersService $webhookListenerService;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
        private readonly LoggerInterface $logger,
    ) {
        $this->webhookUrlService = $this->sdkProvider->getService(SdkWebhookURLsService::class);
        $this->webhookListenerService = $this->sdkProvider->getService(SdkWebhookListenersService::class);
    }

    public function createListener(int $spaceId, int $webhookUrlId, WebhookListenerEnum $entity, array $eventStates, string $name): int
    {
        $this->logger->debug("Creating Webhook Listener in space $spaceId for URL ID $webhookUrlId. Entity: {$entity->value}, Name: $name");

        $sdkEntity = new SdkWebhookListenerCreate();
        $sdkEntity->setName($name);
        $sdkEntity->setUrl($webhookUrlId);
        $sdkEntity->setEntity($entity->value);
        $sdkEntity->setEntityStates($eventStates);
        $sdkEntity->setState(SdkCreationEntityState::ACTIVE);

        // V2: postWebhooksListeners
        $result = $this->webhookListenerService->postWebhooksListeners($spaceId, $sdkEntity);
        return (int)$result->getId();
    }

    public function createUrl(int $spaceId, string $url, string $name): int
    {
        $this->logger->debug("Creating Webhook URL config in space $spaceId: $name -> $url");

        $entity = new SdkWebhookUrlCreate();
        $entity->setUrl($url);
        $entity->setName($name);
        $entity->setState(SdkCreationEntityState::ACTIVE);

        // V2: postWebhooksUrls
        $result = $this->webhookUrlService->postWebhooksUrls($spaceId, $entity);

        return (int)$result->getId();
    }

    public function deleteListener(int $spaceId, int $listenerId): void
    {
        $this->logger->debug("Deleting Webhook Listener ID $listenerId in space $spaceId");
        $this->webhookListenerService->deleteWebhooksListenersId($listenerId, $spaceId);
    }

    public function deleteUrl(int $spaceId, int $webhookUrlId): void
    {
        $this->logger->debug("Deleting Webhook URL ID $webhookUrlId in space $spaceId");
        $this->webhookUrlService->deleteWebhooksUrlsId($webhookUrlId, $spaceId);
    }

    public function getUrl(int $spaceId, int $webhookUrlId): WebhookUrl
    {
        $this->logger->debug("Getting Webhook URL ID $webhookUrlId in space $spaceId");
        $sdkUrl = $this->webhookUrlService->getWebhooksUrlsId($webhookUrlId, $spaceId);

        return new WebhookUrl(
            (int)$sdkUrl->getId(),
            $sdkUrl->getName(),
            $sdkUrl->getUrl(),
            (int)$sdkUrl->getState(),
        );
    }

    public function getWebhookListeners(int $spaceId, int $urlId): array
    {
        $this->logger->debug("Getting Webhook Listeners for URL ID $urlId in space $spaceId");

        // V2 Search: query string
        $query = "url.id:$urlId";
        $results = $this->webhookListenerService->getWebhooksListenersSearch($spaceId, null, 100, null, null, $query);
        $data = (is_object($results) && method_exists($results, 'getData')) ? $results->getData() : (array)$results;

        return array_map(function ($sdkListener) {
            return new WebhookListener(
                (int)$sdkListener->getId(),
                $sdkListener->getName(),
                (int)$sdkListener->getEntity(),
                $sdkListener->getEntityStates() ?? [],
            );
        }, $data);
    }

    public function getWebhookUrls(int $spaceId, ?string $state = 'ACTIVE'): array
    {
        $this->logger->debug("Getting Webhook URLs in space $spaceId" . ($state ? " (state: $state)" : ' (all states)'));

        if ($state !== null) {
            // Filter is applied server-side via API search query.
            $results = $this->webhookUrlService->getWebhooksUrlsSearch(
                $spaceId,
                null,              // expand
                100,               // limit (API maximum)
                null,              // offset
                null,              // order
                "state:$state",    // server-side state filter
            );
        } else {
            // No state filter — use the plain list endpoint.
            $results = $this->webhookUrlService->getWebhooksUrls($spaceId, null, null, null, 100, null);
        }

        $data = (is_object($results) && method_exists($results, 'getData'))
            ? $results->getData()
            : (array) $results;

        return array_map(function ($sdkUrl) {
            return new WebhookUrl(
                (int) $sdkUrl->getId(),
                (string) $sdkUrl->getName(),
                (string) $sdkUrl->getUrl(),
                (int) $sdkUrl->getState(),
            );
        }, $data);
    }

    public function listListeners(int $spaceId): array
    {
        $this->logger->debug("Listing Webhook Listeners in space $spaceId");
        $results = $this->webhookListenerService->getWebhooksListeners($spaceId, null, null, null, 100, null);
        $data = (is_object($results) && method_exists($results, 'getData')) ? $results->getData() : (array)$results;

        return array_map(function ($sdkListener) {
            return new WebhookListener(
                (int)$sdkListener->getId(),
                $sdkListener->getName(),
                (int)$sdkListener->getEntity(),
                $sdkListener->getEntityStates() ?? [],
            );
        }, $data);
    }

    public function listUrls(int $spaceId): array
    {
        $this->logger->debug("Listing Webhook URLs in space $spaceId");
        // V2 Search: using generic query or empty for all.
        // Use the standard Webhook URL retrieval method.
        $results = $this->webhookUrlService->getWebhooksUrls($spaceId, null, null, null, 100, null);
        $data = (is_object($results) && method_exists($results, 'getData')) ? $results->getData() : (array)$results;

        return array_map(function ($sdkUrl) {
            return new WebhookUrl(
                (int)$sdkUrl->getId(),
                $sdkUrl->getName(),
                $sdkUrl->getUrl(),
                (int)$sdkUrl->getState(),
            );
        }, $data);
    }

    public function updateListener(int $spaceId, int $listenerId, WebhookListenerEnum $entity, array $eventStates): void
    {
        $this->logger->debug("Updating Webhook Listener ID $listenerId in space $spaceId. Entity: {$entity->value}");

        $currentListener = $this->webhookListenerService->getWebhooksListenersId($listenerId, $spaceId);

        $update = new SdkWebhookListenerUpdate();
        $update->setVersion($currentListener->getVersion());
        $update->setEntityStates($eventStates);

        $this->webhookListenerService->patchWebhooksListenersId($listenerId, $spaceId, $update);
    }

    public function updateUrl(int $spaceId, int $webhookUrlId, string $newUrl): void
    {
        $this->logger->debug("Updating Webhook URL ID $webhookUrlId in space $spaceId to $newUrl");

        $currentUrl = $this->webhookUrlService->getWebhooksUrlsId($webhookUrlId, $spaceId);

        $update = new SdkWebhookUrlUpdate();
        $update->setVersion($currentUrl->getVersion());
        $update->setName($currentUrl->getName());
        $update->setState($currentUrl->getState());
        $update->setUrl($newUrl);

        $this->webhookUrlService->patchWebhooksUrlsId($webhookUrlId, $spaceId, $update);
    }
}
