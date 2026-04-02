<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Render\JsonStringableTrait;

/**
 * Class WebhookConfig
 *
 * Value object representing the configuration for a webhook.
 */
class WebhookConfig
{
    use JsonStringableTrait;

    /**
     * WebhookConfig constructor.
     *
     * @param string $url The endpoint URL for the webhook.
     * @param string $name A unique internal name for this webhook.
     * @param int $entityId The ID of the entity to listen to (e.g., Transaction ID).
     * @param int $eventStateId The ID of the state that triggers the event.
     */
    public function __construct(
        public readonly string $url,
        public readonly string $name,
        public readonly int $entityId,
        public readonly string $eventStateId,
    ) {
    }
}
