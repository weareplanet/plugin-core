<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund;

use WeArePlanet\PluginCore\SharedKernel\AbstractCollection;

/**
 * Strictly typed, iterable collection of {@see Refund} entities.
 *
 * @extends AbstractCollection<Refund>
 */
final class RefundCollection extends AbstractCollection
{
    public function __construct(Refund ...$items)
    {
        $this->items = array_values($items);
    }

    public function first(): ?Refund
    {
        return $this->items[0] ?? null;
    }
}
