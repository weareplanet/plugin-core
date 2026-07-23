<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund\LineItem;

use WeArePlanet\PluginCore\SharedKernel\AbstractCollection;

/**
 * Strictly typed, iterable collection of {@see RefundLineItem} entities.
 *
 * @extends AbstractCollection<RefundLineItem>
 */
final class RefundLineItemCollection extends AbstractCollection
{
    public function __construct(RefundLineItem ...$items)
    {
        $this->items = array_values($items);
    }

    public function first(): ?RefundLineItem
    {
        return $this->items[0] ?? null;
    }
}
