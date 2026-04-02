<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Render\JsonStringableTrait;

/**
 * Class WebhookListener
 *
 * DTO representing a Webhook Listener in the portal.
 */
class WebhookListener
{
    use JsonStringableTrait;

    /**
     * @param int $id
     * @param string $name
     * @param int $entityId
     * @param array<string> $entityStates
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $entityId,
        public readonly array $entityStates,
    ) {
    }
}
