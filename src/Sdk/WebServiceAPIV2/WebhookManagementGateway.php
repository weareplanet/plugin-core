<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener as WebhookListenerEnum;
use WeArePlanet\PluginCore\Webhook\Exception\WebhookManagementException;
use WeArePlanet\PluginCore\Webhook\WebhookListener;
use WeArePlanet\PluginCore\Webhook\WebhookListenerCollection;
use WeArePlanet\PluginCore\Webhook\WebhookManagementGatewayInterface;
use WeArePlanet\PluginCore\Webhook\WebhookUrl;
use WeArePlanet\PluginCore\Webhook\WebhookUrlCollection;
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
#[LogContext(domain: 'webhook')]
class WebhookManagementGateway implements WebhookManagementGatewayInterface
{
    use DomainLoggerTrait;
    private SdkWebhookURLsService $webhookUrlService;
    private SdkWebhookListenersService $webhookListenerService;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
        $this->webhookUrlService = $this->sdkProvider->getService(SdkWebhookURLsService::class);
        $this->webhookListenerService = $this->sdkProvider->getService(SdkWebhookListenersService::class);
    }

    /**
     * Maps an SDK webhook listener to the domain WebhookListener DTO.
     *
     * @param mixed $sdkListener The SDK webhook listener object.
     * @return WebhookListener The domain listener.
     */
    private function mapToWebhookListener($sdkListener): WebhookListener
    {
        return new WebhookListener(
            (int) $sdkListener->getId(),
            (string) $sdkListener->getName(),
            (int) $sdkListener->getEntity(),
            $sdkListener->getEntityStates() ?? [],
        );
    }

    /**
     * Maps an SDK webhook URL to the domain WebhookUrl DTO.
     *
     * @param mixed $sdkUrl The SDK webhook URL object.
     * @return WebhookUrl The domain URL.
     */
    private function mapToWebhookUrl($sdkUrl): WebhookUrl
    {
        return new WebhookUrl(
            (int) $sdkUrl->getId(),
            (string) $sdkUrl->getName(),
            (string) $sdkUrl->getUrl(),
            (int) $sdkUrl->getState(),
        );
    }

    public function createListener(
        int $spaceId,
        int $webhookUrlId,
        WebhookListenerEnum $entity,
        array $eventStates,
        string $name,
        bool $notifyEveryChange = false,
    ): WebhookListener {
        $this->logger->debug("Creating Webhook Listener.", [
            'spaceId' => $spaceId,
            'webhookUrlId' => $webhookUrlId,
            'entity' => $entity->value,
            'name' => $name,
        ]);

        return $this->wrapSdkCall(function () use ($spaceId, $webhookUrlId, $entity, $eventStates, $name, $notifyEveryChange): WebhookListener {
            $sdkEntity = new SdkWebhookListenerCreate();
            $sdkEntity->setName($name);
            $sdkEntity->setUrl($webhookUrlId);
            $sdkEntity->setEntity($entity->value);
            $sdkEntity->setEntityStates($eventStates);
            $sdkEntity->setState(SdkCreationEntityState::ACTIVE);
            $sdkEntity->setNotifyEveryChange($notifyEveryChange);

            // V2: postWebhooksListeners returns the fully hydrated listener entity.
            $result = $this->webhookListenerService->postWebhooksListeners($spaceId, $sdkEntity);
            return $this->mapToWebhookListener($result);
        }, 'Failed to create the webhook listener.', ['spaceId' => $spaceId, 'webhookUrlId' => $webhookUrlId]);
    }

    public function createUrl(int $spaceId, string $url, string $name): WebhookUrl
    {
        $this->logger->debug("Creating Webhook URL config.", [
            'spaceId' => $spaceId,
            'name' => $name,
            'url' => $url,
        ]);

        return $this->wrapSdkCall(function () use ($spaceId, $url, $name): WebhookUrl {
            $entity = new SdkWebhookUrlCreate();
            $entity->setUrl($url);
            $entity->setName($name);
            $entity->setState(SdkCreationEntityState::ACTIVE);

            // V2: postWebhooksUrls returns the fully hydrated URL entity.
            $result = $this->webhookUrlService->postWebhooksUrls($spaceId, $entity);

            return $this->mapToWebhookUrl($result);
        }, 'Failed to create the webhook URL.', ['spaceId' => $spaceId, 'url' => $url]);
    }

    public function deleteListener(int $spaceId, int $listenerId): void
    {
        $this->logger->debug("Deleting Webhook Listener.", [
            'listenerId' => $listenerId,
            'spaceId' => $spaceId,
        ]);

        $this->wrapSdkCall(function () use ($spaceId, $listenerId): void {
            $this->webhookListenerService->deleteWebhooksListenersId($listenerId, $spaceId);
        }, 'Failed to delete the webhook listener.', ['spaceId' => $spaceId, 'listenerId' => $listenerId]);
    }

    public function deleteUrl(int $spaceId, int $webhookUrlId): void
    {
        $this->logger->debug("Deleting Webhook URL.", [
            'webhookUrlId' => $webhookUrlId,
            'spaceId' => $spaceId,
        ]);

        $this->wrapSdkCall(function () use ($spaceId, $webhookUrlId): void {
            $this->webhookUrlService->deleteWebhooksUrlsId($webhookUrlId, $spaceId);
        }, 'Failed to delete the webhook URL.', ['spaceId' => $spaceId, 'webhookUrlId' => $webhookUrlId]);
    }

    public function getUrl(int $spaceId, int $webhookUrlId): WebhookUrl
    {
        $this->logger->debug("Getting Webhook URL.", [
            'webhookUrlId' => $webhookUrlId,
            'spaceId' => $spaceId,
        ]);

        return $this->wrapSdkCall(function () use ($spaceId, $webhookUrlId): WebhookUrl {
            $sdkUrl = $this->webhookUrlService->getWebhooksUrlsId($webhookUrlId, $spaceId);

            return $this->mapToWebhookUrl($sdkUrl);
        }, 'Failed to read the webhook URL.', ['spaceId' => $spaceId, 'webhookUrlId' => $webhookUrlId]);
    }

    public function getWebhookListeners(int $spaceId, int $urlId): WebhookListenerCollection
    {
        $this->logger->debug("Getting Webhook Listeners for URL.", [
            'urlId' => $urlId,
            'spaceId' => $spaceId,
        ]);

        return $this->wrapSdkCall(function () use ($spaceId, $urlId): WebhookListenerCollection {
            // V2 Search: query string
            $query = "url.id:$urlId";
            $results = $this->webhookListenerService->getWebhooksListenersSearch($spaceId, null, 100, null, null, $query);
            $data = (is_object($results) && method_exists($results, 'getData')) ? $results->getData() : (array)$results;

            return new WebhookListenerCollection(...array_map([$this, 'mapToWebhookListener'], $data));
        }, 'Failed to fetch the webhook listeners.', ['spaceId' => $spaceId, 'urlId' => $urlId]);
    }

    public function getWebhookUrls(int $spaceId, ?string $state = 'ACTIVE'): WebhookUrlCollection
    {
        $this->logger->debug("Getting Webhook URLs.", [
            'spaceId' => $spaceId,
            'state' => $state,
        ]);

        return $this->wrapSdkCall(function () use ($spaceId, $state): WebhookUrlCollection {
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

            return new WebhookUrlCollection(...array_map([$this, 'mapToWebhookUrl'], $data));
        }, 'Failed to fetch the webhook URLs.', ['spaceId' => $spaceId]);
    }

    public function listListeners(int $spaceId): WebhookListenerCollection
    {
        $this->logger->debug("Listing Webhook Listeners.", ['spaceId' => $spaceId]);

        return $this->wrapSdkCall(function () use ($spaceId): WebhookListenerCollection {
            $results = $this->webhookListenerService->getWebhooksListeners($spaceId, null, null, null, 100, null);
            $data = (is_object($results) && method_exists($results, 'getData')) ? $results->getData() : (array)$results;

            return new WebhookListenerCollection(...array_map([$this, 'mapToWebhookListener'], $data));
        }, 'Failed to list the webhook listeners.', ['spaceId' => $spaceId]);
    }

    public function listUrls(int $spaceId): WebhookUrlCollection
    {
        $this->logger->debug("Listing Webhook URLs.", ['spaceId' => $spaceId]);

        return $this->wrapSdkCall(function () use ($spaceId): WebhookUrlCollection {
            // V2 Search: using generic query or empty for all.
            // Use the standard Webhook URL retrieval method.
            $results = $this->webhookUrlService->getWebhooksUrls($spaceId, null, null, null, 100, null);
            $data = (is_object($results) && method_exists($results, 'getData')) ? $results->getData() : (array)$results;

            return new WebhookUrlCollection(...array_map([$this, 'mapToWebhookUrl'], $data));
        }, 'Failed to list the webhook URLs.', ['spaceId' => $spaceId]);
    }

    public function updateListener(int $spaceId, int $listenerId, WebhookListenerEnum $entity, array $eventStates): WebhookListener
    {
        $this->logger->debug("Updating Webhook Listener.", [
            'listenerId' => $listenerId,
            'spaceId' => $spaceId,
            'entity' => $entity->value,
        ]);

        return $this->wrapSdkCall(function () use ($spaceId, $listenerId, $eventStates): WebhookListener {
            $currentListener = $this->webhookListenerService->getWebhooksListenersId($listenerId, $spaceId);

            $update = new SdkWebhookListenerUpdate();
            $update->setVersion($currentListener->getVersion());
            $update->setEntityStates($eventStates);

            // patchWebhooksListenersId returns the fully hydrated, updated listener entity.
            $result = $this->webhookListenerService->patchWebhooksListenersId($listenerId, $spaceId, $update);

            return $this->mapToWebhookListener($result);
        }, 'Failed to update the webhook listener.', ['spaceId' => $spaceId, 'listenerId' => $listenerId]);
    }

    public function updateUrl(int $spaceId, int $webhookUrlId, string $newUrl): WebhookUrl
    {
        $this->logger->debug("Updating Webhook URL.", [
            'webhookUrlId' => $webhookUrlId,
            'spaceId' => $spaceId,
            'newUrl' => $newUrl,
        ]);

        return $this->wrapSdkCall(function () use ($spaceId, $webhookUrlId, $newUrl): WebhookUrl {
            $currentUrl = $this->webhookUrlService->getWebhooksUrlsId($webhookUrlId, $spaceId);

            $update = new SdkWebhookUrlUpdate();
            $update->setVersion($currentUrl->getVersion());
            $update->setName($currentUrl->getName());
            $update->setState($currentUrl->getState());
            $update->setUrl($newUrl);

            // patchWebhooksUrlsId returns the fully hydrated, updated URL entity.
            $result = $this->webhookUrlService->patchWebhooksUrlsId($webhookUrlId, $spaceId, $update);

            return $this->mapToWebhookUrl($result);
        }, 'Failed to update the webhook URL.', ['spaceId' => $spaceId, 'webhookUrlId' => $webhookUrlId]);
    }

    /**
     * Runs an SDK interaction, wrapping any failure in a domain
     * WebhookManagementException so SDK exceptions never leak out.
     *
     * @template T
     * @param callable(): T $operation
     * @param string $errorMessage
     * @param array<string, mixed> $logContext
     * @return T
     */
    private function wrapSdkCall(callable $operation, string $errorMessage, array $logContext = []): mixed
    {
        try {
            return $operation();
        } catch (\Throwable $e) {
            $this->logger->error($errorMessage, $logContext + ['exception' => $e]);
            throw new WebhookManagementException(
                $errorMessage . ' ' . $e->getMessage(),
                new LocalizedString($errorMessage),
                $e,
            );
        }
    }
}
