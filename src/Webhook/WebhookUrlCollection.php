<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\SharedKernel\AbstractCollection;

/**
 * Strictly typed, iterable collection of {@see WebhookUrl} DTOs.
 *
 * @extends AbstractCollection<WebhookUrl>
 */
final class WebhookUrlCollection extends AbstractCollection
{
    public function __construct(WebhookUrl ...$items)
    {
        $this->items = array_values($items);
    }

    public function first(): ?WebhookUrl
    {
        return $this->items[0] ?? null;
    }
}
