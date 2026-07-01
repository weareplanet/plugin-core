<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\PaymentMethod;

use WeArePlanet\PluginCore\SharedKernel\AbstractCollection;

/**
 * Strictly typed, iterable collection of {@see PaymentMethod} entities.
 *
 * @extends AbstractCollection<PaymentMethod>
 */
final class PaymentMethodCollection extends AbstractCollection
{
    public function __construct(PaymentMethod ...$items)
    {
        $this->items = array_values($items);
    }

    public function first(): ?PaymentMethod
    {
        return $this->items[0] ?? null;
    }
}
