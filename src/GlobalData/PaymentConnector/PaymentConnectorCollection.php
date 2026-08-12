<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\GlobalData\PaymentConnector;

use WeArePlanet\PluginCore\SharedKernel\AbstractCollection;

/**
 * Strictly typed, iterable collection of {@see PaymentConnector} entities.
 *
 * @extends AbstractCollection<PaymentConnector>
 */
final class PaymentConnectorCollection extends AbstractCollection
{
    public function __construct(PaymentConnector ...$items)
    {
        $this->items = array_values($items);
    }

    /**
     * Finds the connector with the given ID.
     *
     * @param int $id The ID of the connector to look up.
     * @return PaymentConnector|null The matching connector, or null when this
     *         collection has none.
     */
    public function findById(int $id): ?PaymentConnector
    {
        foreach ($this->items as $connector) {
            if ($connector->id === $id) {
                return $connector;
            }
        }

        return null;
    }
}
