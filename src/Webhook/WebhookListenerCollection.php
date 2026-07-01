<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\SharedKernel\AbstractCollection;

/**
 * Strictly typed, iterable collection of {@see WebhookListener} DTOs.
 *
 * @extends AbstractCollection<WebhookListener>
 */
final class WebhookListenerCollection extends AbstractCollection
{
    public function __construct(WebhookListener ...$items)
    {
        $this->items = array_values($items);
    }

    public function first(): ?WebhookListener
    {
        return $this->items[0] ?? null;
    }
}
