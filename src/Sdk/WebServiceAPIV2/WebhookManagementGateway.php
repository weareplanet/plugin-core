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

        try {
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
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create the webhook listener.', ['spaceId' => $spaceId, 'webhookUrlId' => $webhookUrlId, 'exception' => $e]);

            throw SdkProvider::wrapException(
                $e,
                WebhookManagementException::class,
                'createListener',
                ['spaceId' => $spaceId, 'webhookUrlId' => $webhookUrlId],
                'Failed to create the webhook listener.',
            );
        }
    }

    public function createUrl(int $spaceId, string $url, string $name): WebhookUrl
    {
        $this->logger->debug("Creating Webhook URL config.", [
            'spaceId' => $spaceId,
            'name' => $name,
            'url' => $url,
        ]);

        try {
            $entity = new SdkWebhookUrlCreate();
            $entity->setUrl($url);
            $entity->setName($name);
            $entity->setState(SdkCreationEntityState::ACTIVE);

            // V2: postWebhooksUrls returns the fully hydrated URL entity.
            $result = $this->webhookUrlService->postWebhooksUrls($spaceId, $entity);

            return $this->mapToWebhookUrl($result);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create the webhook URL.', ['spaceId' => $spaceId, 'url' => $url, 'exception' => $e]);

            throw SdkProvider::wrapException(
                $e,
                WebhookManagementException::class,
                'createUrl',
                ['spaceId' => $spaceId, 'url' => $url],
                'Failed to create the webhook URL.',
            );
        }
    }

    public function deleteListener(int $spaceId, int $listenerId): void
    {
        $this->logger->debug("Deleting Webhook Listener.", [
            'listenerId' => $listenerId,
            'spaceId' => $spaceId,
        ]);

        try {
            $this->webhookListenerService->deleteWebhooksListenersId($listenerId, $spaceId);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete the webhook listener.', ['spaceId' => $spaceId, 'listenerId' => $listenerId, 'exception' => $e]);

            throw SdkProvider::wrapException(
                $e,
                WebhookManagementException::class,
                'deleteListener',
                ['spaceId' => $spaceId, 'listenerId' => $listenerId],
                'Failed to delete the webhook listener.',
            );
        }
    }

    public function deleteUrl(int $spaceId, int $webhookUrlId): void
    {
        $this->logger->debug("Deleting Webhook URL.", [
            'webhookUrlId' => $webhookUrlId,
            'spaceId' => $spaceId,
        ]);

        try {
            $this->webhookUrlService->deleteWebhooksUrlsId($webhookUrlId, $spaceId);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete the webhook URL.', ['spaceId' => $spaceId, 'webhookUrlId' => $webhookUrlId, 'exception' => $e]);

            throw SdkProvider::wrapException(
                $e,
                WebhookManagementException::class,
                'deleteUrl',
                ['spaceId' => $spaceId, 'webhookUrlId' => $webhookUrlId],
                'Failed to delete the webhook URL.',
            );
        }
    }

    public function getUrl(int $spaceId, int $webhookUrlId): WebhookUrl
    {
        $this->logger->debug("Getting Webhook URL.", [
            'webhookUrlId' => $webhookUrlId,
            'spaceId' => $spaceId,
        ]);

        try {
            $sdkUrl = $this->webhookUrlService->getWebhooksUrlsId($webhookUrlId, $spaceId);

            return $this->mapToWebhookUrl($sdkUrl);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to read the webhook URL.', ['spaceId' => $spaceId, 'webhookUrlId' => $webhookUrlId, 'exception' => $e]);

            throw SdkProvider::wrapException(
                $e,
                WebhookManagementException::class,
                'getUrl',
                ['spaceId' => $spaceId, 'webhookUrlId' => $webhookUrlId],
                'Failed to read the webhook URL.',
            );
        }
    }

    public function getWebhookListeners(int $spaceId, int $urlId): WebhookListenerCollection
    {
        $this->logger->debug("Getting Webhook Listeners for URL.", [
            'urlId' => $urlId,
            'spaceId' => $spaceId,
        ]);

        try {
            // V2 Search: query string
            $query = "url.id:$urlId";
            $results = $this->webhookListenerService->getWebhooksListenersSearch($spaceId, null, 100, null, null, $query);
            $data = (is_object($results) && method_exists($results, 'getData')) ? $results->getData() : (array)$results;

            return new WebhookListenerCollection(...array_map([$this, 'mapToWebhookListener'], $data));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch the webhook listeners.', ['spaceId' => $spaceId, 'urlId' => $urlId, 'exception' => $e]);

            throw SdkProvider::wrapException(
                $e,
                WebhookManagementException::class,
                'getWebhookListeners',
                ['spaceId' => $spaceId, 'urlId' => $urlId],
                'Failed to fetch the webhook listeners.',
            );
        }
    }

    public function getWebhookUrls(int $spaceId, ?string $state = 'ACTIVE'): WebhookUrlCollection
    {
        $this->logger->debug("Getting Webhook URLs.", [
            'spaceId' => $spaceId,
            'state' => $state,
        ]);

        try {
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
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch the webhook URLs.', ['spaceId' => $spaceId, 'exception' => $e]);

            throw SdkProvider::wrapException(
                $e,
                WebhookManagementException::class,
                'getWebhookUrls',
                ['spaceId' => $spaceId],
                'Failed to fetch the webhook URLs.',
            );
        }
    }

    public function listListeners(int $spaceId): WebhookListenerCollection
    {
        $this->logger->debug("Listing Webhook Listeners.", ['spaceId' => $spaceId]);

        try {
            $results = $this->webhookListenerService->getWebhooksListeners($spaceId, null, null, null, 100, null);
            $data = (is_object($results) && method_exists($results, 'getData')) ? $results->getData() : (array)$results;

            return new WebhookListenerCollection(...array_map([$this, 'mapToWebhookListener'], $data));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list the webhook listeners.', ['spaceId' => $spaceId, 'exception' => $e]);

            throw SdkProvider::wrapException(
                $e,
                WebhookManagementException::class,
                'listListeners',
                ['spaceId' => $spaceId],
                'Failed to list the webhook listeners.',
            );
        }
    }

    public function listUrls(int $spaceId): WebhookUrlCollection
    {
        $this->logger->debug("Listing Webhook URLs.", ['spaceId' => $spaceId]);

        try {
            // V2 Search: using generic query or empty for all.
            // Use the standard Webhook URL retrieval method.
            $results = $this->webhookUrlService->getWebhooksUrls($spaceId, null, null, null, 100, null);
            $data = (is_object($results) && method_exists($results, 'getData')) ? $results->getData() : (array)$results;

            return new WebhookUrlCollection(...array_map([$this, 'mapToWebhookUrl'], $data));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list the webhook URLs.', ['spaceId' => $spaceId, 'exception' => $e]);

            throw SdkProvider::wrapException(
                $e,
                WebhookManagementException::class,
                'listUrls',
                ['spaceId' => $spaceId],
                'Failed to list the webhook URLs.',
            );
        }
    }

    public function updateListener(int $spaceId, int $listenerId, WebhookListenerEnum $entity, array $eventStates): WebhookListener
    {
        $this->logger->debug("Updating Webhook Listener.", [
            'listenerId' => $listenerId,
            'spaceId' => $spaceId,
            'entity' => $entity->value,
        ]);

        try {
            $currentListener = $this->webhookListenerService->getWebhooksListenersId($listenerId, $spaceId);

            $update = new SdkWebhookListenerUpdate();
            $update->setVersion($currentListener->getVersion());
            $update->setEntityStates($eventStates);

            // patchWebhooksListenersId returns the fully hydrated, updated listener entity.
            $result = $this->webhookListenerService->patchWebhooksListenersId($listenerId, $spaceId, $update);

            return $this->mapToWebhookListener($result);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update the webhook listener.', ['spaceId' => $spaceId, 'listenerId' => $listenerId, 'exception' => $e]);

            throw SdkProvider::wrapException(
                $e,
                WebhookManagementException::class,
                'updateListener',
                ['spaceId' => $spaceId, 'listenerId' => $listenerId],
                'Failed to update the webhook listener.',
            );
        }
    }

    public function updateUrl(int $spaceId, int $webhookUrlId, string $newUrl): WebhookUrl
    {
        $this->logger->debug("Updating Webhook URL.", [
            'webhookUrlId' => $webhookUrlId,
            'spaceId' => $spaceId,
            'newUrl' => $newUrl,
        ]);

        try {
            $currentUrl = $this->webhookUrlService->getWebhooksUrlsId($webhookUrlId, $spaceId);

            $update = new SdkWebhookUrlUpdate();
            $update->setVersion($currentUrl->getVersion());
            $update->setName($currentUrl->getName());
            $update->setState($currentUrl->getState());
            $update->setUrl($newUrl);

            // patchWebhooksUrlsId returns the fully hydrated, updated URL entity.
            $result = $this->webhookUrlService->patchWebhooksUrlsId($webhookUrlId, $spaceId, $update);

            return $this->mapToWebhookUrl($result);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update the webhook URL.', ['spaceId' => $spaceId, 'webhookUrlId' => $webhookUrlId, 'exception' => $e]);

            throw SdkProvider::wrapException(
                $e,
                WebhookManagementException::class,
                'updateUrl',
                ['spaceId' => $spaceId, 'webhookUrlId' => $webhookUrlId],
                'Failed to update the webhook URL.',
            );
        }
    }

}
