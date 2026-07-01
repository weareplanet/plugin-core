<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\SharedKernel\AbstractCollection;

/**
 * Strictly typed, iterable collection of {@see Transaction} entities.
 *
 * @extends AbstractCollection<Transaction>
 */
final class TransactionCollection extends AbstractCollection
{
    public function __construct(Transaction ...$items)
    {
        $this->items = array_values($items);
    }

    public function first(): ?Transaction
    {
        return $this->items[0] ?? null;
    }
}
