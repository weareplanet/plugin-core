<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Render\JsonStringableTrait;

class WebhookUrl
{
    use JsonStringableTrait;

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $url,
        public readonly int $state,
    ) {
    }
}
