<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook\Listener;

use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;

/**
 * A registry that holds and finds webhook listeners.
 *
 * This version uses a map for direct, high-performance lookups.
 * It is designed to be easily configured by client plugins.
 */
class WebhookListenerRegistry
{
    /**
     * @var array<string, array<string, WebhookListenerInterface>>
     */
    private array $listeners = [];

    /**
     * Registers a listener for a specific webhook name and state.
     *
     * @param WebhookListener $name The name of the webhook (e.g., WebhookName::Transaction).
     * @param string $state The state of the webhook (e.g., 'COMPLETED').
     * @param WebhookListenerInterface $listener The listener instance to handle this event.
     */
    public function addListener(WebhookListener $name, string $state, WebhookListenerInterface $listener): void
    {
        $this->listeners[$name->value][$state] = $listener;
    }

    /**
     * Finds the specific listener that supports the given webhook criteria.
     *
     * @param WebhookListener $name The name of the webhook.
     * @param string $state The state of the webhook.
     * @return WebhookListenerInterface|null The matching listener, or null if none is found.
     */
    public function findListener(WebhookListener $name, string $state): ?WebhookListenerInterface
    {
        return $this->listeners[$name->value][$state] ?? null;
    }

    /**
     * Checks if a webhook listener with the given name and state exists in the registry.
     *
     * @param WebhookListener $name The webhook listener instance to check for.
     * @param string $state The state associated with the listener.
     * @return bool Returns true if the listener with the specified state exists, false otherwise.
     */
    public function hasListener(WebhookListener $name, string $state): bool
    {
        return isset($this->listeners[$name->value][$state]);
    }

    /**
     * Retrieves all registered listeners.
     *
     * @return array<string, array<string, WebhookListenerInterface>> An associative array of all listeners.
     */
    public function getAllListeners(): array
    {
        return $this->listeners;
    }
}
