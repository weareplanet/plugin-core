<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener as WebhookListenerEnum;
use WeArePlanet\PluginCore\Webhook\Listener\WebhookListenerRegistry;

/**
 * Class WebhookService
 *
 * Domain service for managing webhook subscriptions and validating payloads.
 */
class WebhookService
{
    /**
     * WebhookService constructor.
     *
     * @param WebhookManagementGatewayInterface $managementGateway
     * @param WebhookSignatureGatewayInterface $signatureGateway
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly WebhookManagementGatewayInterface $managementGateway,
        private readonly WebhookSignatureGatewayInterface $signatureGateway,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Creates a webhook listener.
     *
     * @param int $spaceId
     * @param int $urlId
     * @param WebhookListenerEnum $entity
     * @param array<string> $eventStates
     * @param string $name
     * @return int
     */
    public function createWebhookListener(int $spaceId, int $urlId, WebhookListenerEnum $entity, array $eventStates, string $name, bool $notifyEveryChange = false): int
    {
        $listenerId = $this->managementGateway->createListener($spaceId, $urlId, $entity, $eventStates, $name, $notifyEveryChange);
        $this->logger->debug("Created Webhook Listener ID: $listenerId");
        return $listenerId;
    }

    /**
     * Creates a webhook URL.
     *
     * @param int $spaceId
     * @param string $url
     * @param string $name
     * @return int
     */
    public function createWebhookUrl(int $spaceId, string $url, string $name): int
    {
        $urlId = $this->managementGateway->createUrl($spaceId, $url, $name);
        $this->logger->debug("Created Webhook URL ID: $urlId");
        return $urlId;
    }

    /**
     * Deletes a webhook listener.
     *
     * @param int $spaceId
     * @param int $listenerId
     * @return void
     */
    public function deleteWebhookListener(int $spaceId, int $listenerId): void
    {
        $this->managementGateway->deleteListener($spaceId, $listenerId);
    }

    /**
     * Deletes all listeners attached to a specific webhook URL.
     *
     * @param int $spaceId
     * @param int $webhookUrlId
     * @return int The number of deleted listeners.
     */
    public function deleteWebhookListenersForUrl(int $spaceId, int $webhookUrlId): int
    {
        $this->logger->debug("Deleting listeners for Webhook URL $webhookUrlId in Space $spaceId.");

        $listeners = $this->getWebhookListeners($spaceId, $webhookUrlId);
        $count = 0;

        foreach ($listeners as $listener) {
            // Listener is now a WebhookListener DTO with public readonly $id property
            $this->deleteWebhookListener($spaceId, $listener->id);
            $count++;
        }

        return $count;
    }

    /**
     * Deletes a webhook URL and optionally its listeners.
     *
     * @param int $spaceId
     * @param int $webhookUrlId
     * @param bool $cascade If true, deletes all listeners attached to this URL first.
     * @return int The number of deleted listeners.
     * @throws \Throwable
     */
    public function deleteWebhookUrl(int $spaceId, int $webhookUrlId, bool $cascade = false): int
    {
        $cascadeText = $cascade ? 'true' : 'false';
        $this->logger->debug("Deleting Webhook URL $webhookUrlId in Space $spaceId (Cascade: $cascadeText).");

        $deletedCount = 0;
        if ($cascade) {
            $deletedCount = $this->deleteWebhookListenersForUrl($spaceId, $webhookUrlId);
        }

        $this->managementGateway->deleteUrl($spaceId, $webhookUrlId);

        return $deletedCount;
    }

    /**
     * Finds an existing listener for the given entity in the provided list.
     *
     * @param WebhookListenerEnum $entity
     * @param WebhookListener[] $listeners
     * @return WebhookListener|null
     */
    private function findListenerForEntity(WebhookListenerEnum $entity, array $listeners): ?WebhookListener
    {
        foreach ($listeners as $listener) {
            if ($listener->entityId === $entity->value) {
                return $listener;
            }
        }
        return null;
    }

    /**
     * Returns the ID of an existing webhook URL matching the given URL, or creates a new one.
     *
     * @param int $spaceId
     * @param string $url
     * @param string $name
     * @return int
     */
    private function getOrCreateWebhookUrl(int $spaceId, string $url, string $name): int
    {
        foreach ($this->getWebhookUrls($spaceId) as $existingUrl) {
            if ($existingUrl->url === $url) {
                return $existingUrl->id;
            }
        }

        return $this->createWebhookUrl($spaceId, $url, $name);
    }

    /**
     * Gets all webhook listeners for a specific URL.
     *
     * @param int $spaceId
     * @param int $urlId
     * @return WebhookListener[]
     */
    public function getWebhookListeners(int $spaceId, int $urlId): array
    {
        return $this->managementGateway->getWebhookListeners($spaceId, $urlId);
    }

    /**
     * Gets all webhook URL definitions in the space.
     *
     * @param int $spaceId
     * @param string|null $state
     * @return WebhookUrl[]
     */
    public function getWebhookUrls(int $spaceId, ?string $state = 'ACTIVE'): array
    {
        return $this->managementGateway->getWebhookUrls($spaceId, $state);
    }

    /**
     * Installs a webhook by creating the URL and the Listener.
     *
     * @param int $spaceId
     * @param WebhookConfig $config
     * @return void
     */
    public function installWebhook(int $spaceId, WebhookConfig $config): void
    {
        $this->logger->debug("Installing Webhook '{$config->name}' for Entity {$config->entity->name} in Space $spaceId.");

        $urlId = $this->createWebhookUrl($spaceId, $config->url, $config->name);
        $this->createWebhookListener($spaceId, $urlId, $config->entity, $config->eventStates, $config->name);
    }

    /**
     * Lists all webhook listeners in the space.
     *
     * @param int $spaceId
     * @return WebhookListener[]
     */
    public function listListeners(int $spaceId): array
    {
        return $this->managementGateway->listListeners($spaceId);
    }

    /**
     * Lists all webhook URL definitions in the space.
     *
     * @param int $spaceId
     * @return WebhookUrl[]
     */
    public function listUrls(int $spaceId): array
    {
        return $this->getWebhookUrls($spaceId, null);
    }

    /**
     * Synchronizes webhook listeners in the given space against the entities configured in the registry.
     *
     * Resolves (or creates) the webhook URL, then iterates over the registry to ensure that a listener
     * exists for every configured entity. When $force is true, any pre-existing listener for an entity
     * is deleted and recreated to pick up state list changes.
     *
     * @param int $spaceId
     * @param string $url The endpoint URL the portal should call.
     * @param string $namePrefix Prefix used for both the URL name and listener names (e.g. 'Magento 2').
     * @param WebhookListenerRegistry $registry The registry holding configured entities and states.
     * @param bool $force When true, recreates listeners that already exist.
     * @return void
     */
    public function synchronizeWebhooks(
        int $spaceId,
        string $url,
        string $namePrefix,
        WebhookListenerRegistry $registry,
        bool $force = false,
    ): void {
        $this->logger->debug("Synchronizing webhooks for Space $spaceId at URL $url.");

        $urlId = $this->getOrCreateWebhookUrl($spaceId, $url, $namePrefix);
        $existingListeners = $this->getWebhookListeners($spaceId, $urlId);

        foreach ($registry->getAllListeners() as $entityValue => $stateMap) {
            $entity = WebhookListenerEnum::from((int)$entityValue);
            $states = array_keys($stateMap);

            $existing = $this->findListenerForEntity($entity, $existingListeners);
            if ($existing !== null) {
                if (!$force) {
                    continue;
                }
                $this->deleteWebhookListener($spaceId, $existing->id);
            }

            $this->createWebhookListener(
                $spaceId,
                $urlId,
                $entity,
                $states,
                $namePrefix . ' ' . $entity->getTechnicalName(),
                $registry->getNotifyEveryChange($entity),
            );
        }
    }

    /**
     * Uninstalls a webhook by removing the Listener and the URL.
     *
     * @param int $spaceId
     * @param int $webhookUrlId
     * @param int $listenerId
     * @return void
     */
    public function uninstallWebhook(int $spaceId, int $webhookUrlId, int $listenerId): void
    {
        $this->logger->debug("Uninstalling Webhook (URL: $webhookUrlId, Listener: $listenerId) in Space $spaceId.");

        try {
            $this->deleteWebhookListener($spaceId, $listenerId);
        } catch (\Throwable $e) {
            $this->logger->error("Failed to delete Webhook Listener $listenerId: {$e->getMessage()}");
        }

        try {
            $this->deleteWebhookUrl($spaceId, $webhookUrlId);
        } catch (\Throwable $e) {
            $this->logger->error("Failed to delete Webhook URL $webhookUrlId: {$e->getMessage()}");
            throw $e;
        }

        $this->logger->debug("Webhook uninstalled successfully.");
    }

    /**
     * Updates a webhook listener.
     *
     * @param int $spaceId
     * @param int $listenerId
     * @param WebhookListenerEnum $entity
     * @param array<string> $eventStates
     * @return void
     */
    public function updateWebhookListener(int $spaceId, int $listenerId, WebhookListenerEnum $entity, array $eventStates): void
    {
        $this->logger->debug("Updating Webhook Listener $listenerId in Space $spaceId.");
        $this->managementGateway->updateListener($spaceId, $listenerId, $entity, $eventStates);
        $this->logger->debug("Webhook Listener updated successfully.");
    }

    /**
     * Updates the URL of an existing webhook.
     *
     * @param int $spaceId
     * @param int $webhookUrlId
     * @param string $newUrl
     * @return void
     */
    public function updateWebhookUrl(int $spaceId, int $webhookUrlId, string $newUrl): void
    {
        $this->logger->debug("Updating Webhook URL $webhookUrlId in Space $spaceId.");
        $this->managementGateway->updateUrl($spaceId, $webhookUrlId, $newUrl);
        $this->logger->debug("Webhook URL updated successfully.");
    }

    /**
     * Validates the incoming webhook payload.
     *
     * @param string $signature
     * @param string $payload
     * @return bool
     */
    public function validatePayload(string $signature, string $payload): bool
    {
        $isValid = $this->signatureGateway->validate($signature, $payload);

        if (!$isValid) {
            $this->logger->warning("Webhook signature validation failed.");
        }

        return $isValid;
    }
}
