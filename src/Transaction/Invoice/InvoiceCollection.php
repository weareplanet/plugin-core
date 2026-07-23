<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Invoice;

use WeArePlanet\PluginCore\SharedKernel\AbstractCollection;

/**
 * Strictly typed, iterable collection of {@see Invoice} entities.
 *
 * @extends AbstractCollection<Invoice>
 */
final class InvoiceCollection extends AbstractCollection
{
    public function __construct(Invoice ...$items)
    {
        $this->items = array_values($items);
    }

    public function first(): ?Invoice
    {
        return $this->items[0] ?? null;
    }
}
