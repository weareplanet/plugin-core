<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Render\JsonStringableTrait;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener as WebhookListenerEnum;

/**
 * Class WebhookConfig
 *
 * Value object representing the configuration for a webhook subscription.
 * Each config defines which entity and states to listen for,
 * and where to send the notification.
 */
class WebhookConfig
{
    use JsonStringableTrait;

    /**
     * @param string $url The endpoint URL for the webhook.
     * @param string $name A unique internal name for this webhook.
     * @param WebhookListenerEnum $entity The entity type to listen to (e.g., Transaction).
     * @param array<string> $eventStates The list of states that trigger the event.
     */
    public function __construct(
        public readonly string $url,
        public readonly string $name,
        public readonly WebhookListenerEnum $entity,
        public readonly array $eventStates,
    ) {
    }
}
