<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\SharedKernel\AbstractCollection;

/**
 * Strictly typed, iterable collection of {@see TransactionComment} entities.
 *
 * @extends AbstractCollection<TransactionComment>
 */
final class TransactionCommentCollection extends AbstractCollection
{
    /**
     * Constructs the collection with TransactionComment items.
     *
     * @param TransactionComment ...$items
     */
    public function __construct(TransactionComment ...$items)
    {
        $this->items = array_values($items);
    }

    /**
     * Returns the first comment in the collection, or null if empty.
     *
     * @return TransactionComment|null
     */
    public function first(): ?TransactionComment
    {
        return $this->items[0] ?? null;
    }
}
