<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener as WebhookListenerEnum;

/**
 * Interface WebhookManagementGatewayInterface
 *
 * Defines the contract for managing webhook URLs and listeners in the portal.
 */
interface WebhookManagementGatewayInterface
{
    /**
     * Creates a webhook listener that links an entity state change to a webhook URL.
     *
     * @param int $spaceId The space ID.
     * @param int $webhookUrlId The ID of the webhook URL definition.
     * @param WebhookListenerEnum $entity The entity (e.g. Transaction).
     * @param array<string> $eventStates The list of states that trigger the event.
     * @param string $name
     * @param bool $notifyEveryChange
     * @return int The ID of the created Webhook Listener.
     */
    public function createListener(
        int $spaceId,
        int $webhookUrlId,
        WebhookListenerEnum $entity,
        array $eventStates,
        string $name,
        bool $notifyEveryChange = false,
    ): int;

    /**
     * Creates a webhook URL definition in the portal.
     *
     * @param int $spaceId The space ID where the webhook URL is defined.
     * @param string $url The actual URL endpoint.
     * @param string $name The name for the webhook URL definition.
     * @return int The ID of the created Webhook URL.
     */
    public function createUrl(
        int $spaceId,
        string $url,
        string $name,
    ): int;

    /**
     * Deletes a webhook listener.
     *
     * @param int $spaceId The space ID.
     * @param int $listenerId The ID of the listener to delete.
     * @return void
     */
    public function deleteListener(
        int $spaceId,
        int $listenerId,
    ): void;

    /**
     * Deletes a webhook URL definition.
     *
     * @param int $spaceId The space ID.
     * @param int $webhookUrlId The ID of the webhook URL definition to delete.
     * @return void
     */
    public function deleteUrl(
        int $spaceId,
        int $webhookUrlId,
    ): void;

    /**
     * Gets a specific webhook URL definition.
     *
     * @param int $spaceId
     * @param int $webhookUrlId
     * @return WebhookUrl
     */
    public function getUrl(
        int $spaceId,
        int $webhookUrlId,
    ): WebhookUrl;

    /**
     * Gets all webhook listeners for a space, filtered by URL.
     *
     * @param int $spaceId
     * @param int $urlId
     * @return WebhookListener[]
     */
    public function getWebhookListeners(
        int $spaceId,
        int $urlId,
    ): array;

    /**
     * Gets webhook URLs for a space, optionally filtered by state.
     * When $state is provided, filtering is done server-side via the API search query.
     * Pass null to receive URLs in any state.
     *
     * @param int         $spaceId
     * @param string|null $state   One of CreationEntityState::* constants, or null for all.
     * @return WebhookUrl[]
     */
    public function getWebhookUrls(
        int $spaceId,
        ?string $state = 'ACTIVE',
    ): array;

    /**
     * Lists webhook listeners in the portal.
     *
     * @param int $spaceId
     * @return WebhookListener[]
     */
    public function listListeners(
        int $spaceId,
    ): array;

    /**
     * Lists webhook URL definitions in the portal.
     *
     * @param int $spaceId
     * @return WebhookUrl[]
     */
    public function listUrls(
        int $spaceId,
    ): array;

    /**
     * Updates an existing webhook listener.
     *
     * @param int $spaceId
     * @param int $listenerId
     * @param WebhookListenerEnum $entity
     * @param array<string> $eventStates
     * @return void
     */
    public function updateListener(
        int $spaceId,
        int $listenerId,
        WebhookListenerEnum $entity,
        array $eventStates,
    ): void;

    /**
     * Updates an existing webhook URL definition.
     *
     * @param int $spaceId The space ID.
     * @param int $webhookUrlId The ID of the webhook URL definition to update.
     * @param string $newUrl The new URL endpoint.
     * @return void
     */
    public function updateUrl(
        int $spaceId,
        int $webhookUrlId,
        string $newUrl,
    ): void;
}
