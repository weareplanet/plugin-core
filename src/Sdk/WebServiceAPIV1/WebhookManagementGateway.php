<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV1;

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
use WeArePlanet\Sdk\Model\CriteriaOperator as SdkCriteriaOperator;
use WeArePlanet\Sdk\Model\EntityQuery as SdkEntityQuery;
use WeArePlanet\Sdk\Model\EntityQueryFilter as SdkEntityQueryFilter;
use WeArePlanet\Sdk\Model\EntityQueryFilterType as SdkEntityQueryFilterType;
use WeArePlanet\Sdk\Model\EntityQueryOrderBy as SdkEntityQueryOrderBy;
use WeArePlanet\Sdk\Model\EntityQueryOrderByType as SdkEntityQueryOrderByType;
use WeArePlanet\Sdk\Model\WebhookListener as SdkWebhookListener;
use WeArePlanet\Sdk\Model\WebhookListenerCreate as SdkWebhookListenerCreate;
use WeArePlanet\Sdk\Model\WebhookListenerUpdate as SdkWebhookListenerUpdate;
use WeArePlanet\Sdk\Model\WebhookUrl as SdkWebhookUrl;
use WeArePlanet\Sdk\Model\WebhookUrlCreate as SdkWebhookUrlCreate;
use WeArePlanet\Sdk\Model\WebhookUrlUpdate as SdkWebhookUrlUpdate;
use WeArePlanet\Sdk\Service\WebhookListenerService as SdkWebhookListenerService;
use WeArePlanet\Sdk\Service\WebhookUrlService as SdkWebhookUrlService;

/**
 * SDK v1 implementation of the WebhookManagementGatewayInterface.
 *
 * Adapts the new interface signatures (enums, typed DTOs) to SDK v1's
 * integer-based entity IDs and raw model objects.
 */
#[LogContext(domain: 'webhook')]
class WebhookManagementGateway implements WebhookManagementGatewayInterface
{
    use DomainLoggerTrait;
    private SdkWebhookListenerService $webhookListenerService;
    private SdkWebhookUrlService $webhookUrlService;

    /**
     * @param SdkProvider $sdkProvider
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
        $this->webhookUrlService = $this->sdkProvider->getService(SdkWebhookUrlService::class);
        $this->webhookListenerService = $this->sdkProvider->getService(SdkWebhookListenerService::class);
    }

    /**
     * Creates a webhook listener using the new enum-based interface.
     *
     * SDK v1 expects integer entity IDs and string state IDs, so we
     * extract $entity->value (the int ID) and pass $eventStates directly
     * because SDK v1's setEntityStates() already accepts an array of strings.
     *
     * @inheritDoc
     */
    public function createListener(int $spaceId, int $webhookUrlId, WebhookListenerEnum $entity, array $eventStates, string $name, bool $notifyEveryChange = false): WebhookListener
    {
        $this->logger->debug("Creating Webhook Listener.", [
            'spaceId' => $spaceId,
            'webhookUrlId' => $webhookUrlId,
            'entity' => $entity->name,
            'eventStates' => $eventStates,
            'name' => $name,
        ]);

        return $this->wrapSdkCall(function () use ($spaceId, $webhookUrlId, $entity, $eventStates, $name, $notifyEveryChange): WebhookListener {
            $listenerCreate = new SdkWebhookListenerCreate();
            $listenerCreate->setName($name);
            $listenerCreate->setUrl($webhookUrlId);

            // SDK v1 expects the entity ID as an integer — use the enum's backing value
            $listenerCreate->setEntity($entity->value);
            // SDK v1's setEntityStates already accepts an array of state strings
            $listenerCreate->setEntityStates($eventStates);
            $listenerCreate->setState(SdkCreationEntityState::ACTIVE);
            $listenerCreate->setNotifyEveryChange($notifyEveryChange);

            // The SDK create call returns the fully hydrated listener entity.
            $result = $this->webhookListenerService->create($spaceId, $listenerCreate);
            return $this->mapToWebhookListener($result);
        }, 'Failed to create the webhook listener.', ['spaceId' => $spaceId, 'webhookUrlId' => $webhookUrlId]);
    }

    /**
     * @inheritDoc
     */
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

            // The SDK create call returns the fully hydrated URL entity.
            $result = $this->webhookUrlService->create($spaceId, $entity);
            return $this->mapToWebhookUrl($result);
        }, 'Failed to create the webhook URL.', ['spaceId' => $spaceId, 'url' => $url]);
    }

    /**
     * @inheritDoc
     */
    public function deleteListener(int $spaceId, int $listenerId): void
    {
        $this->logger->debug("Deleting Webhook Listener.", [
            'listenerId' => $listenerId,
            'spaceId' => $spaceId,
        ]);

        $this->wrapSdkCall(function () use ($spaceId, $listenerId): void {
            $this->webhookListenerService->delete($spaceId, $listenerId);
        }, 'Failed to delete the webhook listener.', ['spaceId' => $spaceId, 'listenerId' => $listenerId]);
    }

    /**
     * @inheritDoc
     */
    public function deleteUrl(int $spaceId, int $webhookUrlId): void
    {
        $this->logger->debug("Deleting Webhook URL.", [
            'webhookUrlId' => $webhookUrlId,
            'spaceId' => $spaceId,
        ]);

        $this->wrapSdkCall(function () use ($spaceId, $webhookUrlId): void {
            $this->webhookUrlService->delete($spaceId, $webhookUrlId);
        }, 'Failed to delete the webhook URL.', ['spaceId' => $spaceId, 'webhookUrlId' => $webhookUrlId]);
    }

    /**
     * @inheritDoc
     */
    public function getUrl(int $spaceId, int $webhookUrlId): WebhookUrl
    {
        $this->logger->debug("Getting Webhook URL.", [
            'webhookUrlId' => $webhookUrlId,
            'spaceId' => $spaceId,
        ]);

        return $this->wrapSdkCall(function () use ($spaceId, $webhookUrlId): WebhookUrl {
            $sdkUrl = $this->webhookUrlService->read($spaceId, $webhookUrlId);

            return $this->mapToWebhookUrl($sdkUrl);
        }, 'Failed to read the webhook URL.', ['spaceId' => $spaceId, 'webhookUrlId' => $webhookUrlId]);
    }

    /**
     * Gets listeners filtered by URL ID, returning typed WebhookListener DTOs.
     *
     * @inheritDoc
     * @return WebhookListenerCollection
     */
    public function getWebhookListeners(int $spaceId, int $urlId): WebhookListenerCollection
    {
        $this->logger->debug("Getting Webhook Listeners for URL.", [
            'urlId' => $urlId,
            'spaceId' => $spaceId,
        ]);

        return $this->wrapSdkCall(function () use ($spaceId, $urlId): WebhookListenerCollection {
            $query = new SdkEntityQuery();

            $filter = new SdkEntityQueryFilter();
            $filter->setFieldName('url.id');
            $filter->setValue($urlId);
            $filter->setOperator(SdkCriteriaOperator::EQUALS);
            $filter->setType(SdkEntityQueryFilterType::LEAF);

            $query->setFilter($filter);
            $query->setNumberOfEntities(100);

            $sdkListeners = $this->webhookListenerService->search($spaceId, $query);

            return new WebhookListenerCollection(...array_map([$this, 'mapToWebhookListener'], $sdkListeners));
        }, 'Failed to fetch the webhook listeners.', ['spaceId' => $spaceId, 'urlId' => $urlId]);
    }

    /**
     * @inheritDoc
     * @return WebhookUrlCollection
     */
    public function getWebhookUrls(int $spaceId, ?string $state = 'ACTIVE'): WebhookUrlCollection
    {
        $this->logger->debug("Getting Webhook URLs.", [
            'spaceId' => $spaceId,
            'state' => $state,
        ]);

        return $this->wrapSdkCall(function () use ($spaceId, $state): WebhookUrlCollection {
            $query = new SdkEntityQuery();
            $orderBy = new SdkEntityQueryOrderBy();
            $orderBy->setFieldName('id');
            $orderBy->setSorting(SdkEntityQueryOrderByType::DESC);
            $query->setOrderBys([$orderBy]);

            if ($state !== null) {
                $filter = new SdkEntityQueryFilter();
                $filter->setFieldName('state');
                $filter->setValue($state);
                $filter->setOperator(SdkCriteriaOperator::EQUALS);
                $filter->setType(SdkEntityQueryFilterType::LEAF);
                $query->setFilter($filter);
            }

            $sdkUrls = $this->webhookUrlService->search($spaceId, $query);

            return new WebhookUrlCollection(...array_map([$this, 'mapToWebhookUrl'], $sdkUrls));
        }, 'Failed to fetch the webhook URLs.', ['spaceId' => $spaceId]);
    }

    /**
     * Lists webhook listeners and maps each SDK object to a WebhookListener DTO.
     *
     * @inheritDoc
     * @return WebhookListenerCollection
     */
    public function listListeners(int $spaceId): WebhookListenerCollection
    {
        $this->logger->debug("Listing Webhook Listeners.", ['spaceId' => $spaceId]);

        return $this->wrapSdkCall(function () use ($spaceId): WebhookListenerCollection {
            $query = new SdkEntityQuery();
            $orderBy = new SdkEntityQueryOrderBy();
            $orderBy->setFieldName('id');
            $orderBy->setSorting(SdkEntityQueryOrderByType::DESC);
            $query->setOrderBys([$orderBy]);

            $sdkListeners = $this->webhookListenerService->search($spaceId, $query);

            return new WebhookListenerCollection(...array_map([$this, 'mapToWebhookListener'], $sdkListeners));
        }, 'Failed to list the webhook listeners.', ['spaceId' => $spaceId]);
    }

    /**
     * Lists webhook URLs and maps each SDK object to a WebhookUrl DTO.
     * By default, lists URLs in all states to preserve existing behavior.
     *
     * @inheritDoc
     * @return WebhookUrlCollection
     */
    public function listUrls(int $spaceId): WebhookUrlCollection
    {
        return $this->getWebhookUrls($spaceId, null);
    }

    /**
     * Maps an SDK WebhookListener object to the domain WebhookListener DTO.
     *
     * Ensures that SDK objects never leak outside the gateway layer.
     *
     * @param SdkWebhookListener $sdkListener The SDK webhook listener object.
     * @return WebhookListener The domain DTO.
     */
    private function mapToWebhookListener(SdkWebhookListener $sdkListener): WebhookListener
    {
        return new WebhookListener(
            id: (int)$sdkListener->getId(),
            name: (string)$sdkListener->getName(),
            entityId: (int)$sdkListener->getEntity(),
            entityStates: $sdkListener->getEntityStates() ?? [],
        );
    }

    /**
     * Maps an SDK WebhookUrl object to the domain WebhookUrl DTO.
     *
     * Ensures that SDK objects never leak outside the gateway layer.
     *
     * @param SdkWebhookUrl $sdkUrl The SDK webhook URL object.
     * @return WebhookUrl The domain DTO.
     */
    private function mapToWebhookUrl(SdkWebhookUrl $sdkUrl): WebhookUrl
    {
        return new WebhookUrl(
            id: (int)$sdkUrl->getId(),
            name: (string)$sdkUrl->getName(),
            url: (string)$sdkUrl->getUrl(),
            state: (int)$sdkUrl->getState(),
        );
    }

    /**
     * Updates an existing webhook listener using the new enum-based interface.
     *
     * Same adapter pattern as createListener: the enum's backing int value
     * is not needed for the update SDK call (entity cannot be changed),
     * but event states are forwarded as-is to SDK v1.
     *
     * @inheritDoc
     */
    public function updateListener(int $spaceId, int $listenerId, WebhookListenerEnum $entity, array $eventStates): WebhookListener
    {
        $this->logger->debug("Updating Webhook Listener.", [
            'listenerId' => $listenerId,
            'spaceId' => $spaceId,
            'entity' => $entity->name,
            'eventStates' => $eventStates,
        ]);

        return $this->wrapSdkCall(function () use ($spaceId, $listenerId, $eventStates): WebhookListener {
            // Read the existing listener to retrieve the current version for optimistic locking.
            $currentListener = $this->webhookListenerService->read($spaceId, $listenerId);

            // Prepare the update — SDK v1 does not allow changing the entity on update,
            // so we only forward the event states.
            $update = new SdkWebhookListenerUpdate();
            $update->setId($listenerId);
            $update->setVersion($currentListener->getVersion());
            $update->setEntityStates($eventStates);

            // Execute the update via the SDK service, which returns the updated entity.
            $result = $this->webhookListenerService->update($spaceId, $update);

            return $this->mapToWebhookListener($result);
        }, 'Failed to update the webhook listener.', ['spaceId' => $spaceId, 'listenerId' => $listenerId]);
    }

    /**
     * @inheritDoc
     */
    public function updateUrl(int $spaceId, int $webhookUrlId, string $newUrl): WebhookUrl
    {
        $this->logger->debug("Updating Webhook URL.", [
            'webhookUrlId' => $webhookUrlId,
            'spaceId' => $spaceId,
            'newUrl' => $newUrl,
        ]);

        return $this->wrapSdkCall(function () use ($spaceId, $webhookUrlId, $newUrl): WebhookUrl {
            // Read the existing URL config to get the version for optimistic locking.
            $currentUrl = $this->webhookUrlService->read($spaceId, $webhookUrlId);

            // Prepare the update payload.
            $update = new SdkWebhookUrlUpdate();
            $update->setId($webhookUrlId);
            $update->setVersion($currentUrl->getVersion());
            $update->setUrl($newUrl);

            // Execute the update operation via the SDK, which returns the updated entity.
            $result = $this->webhookUrlService->update($spaceId, $update);

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
