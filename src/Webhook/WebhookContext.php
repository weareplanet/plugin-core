<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Render\JsonStringableTrait;

/**
 * An immutable value object that holds the context of a webhook event.
 */
class WebhookContext
{
    use JsonStringableTrait;

    public function __construct(
        public readonly string $remoteState,
        public readonly ?string $lastProcessedState,
        public readonly int $entityId,
        public readonly int $spaceId,
    ) {
    }
}
