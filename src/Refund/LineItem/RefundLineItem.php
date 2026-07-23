<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund\LineItem;

use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * A single line item reduction to apply as part of a refund.
 *
 * NOTE: `unitPriceReduction` is the price reduction applied to each of the
 * remaining (non-returned) units, NOT the total reduction for the item.
 * See docs/Refund/README.md for the full calculation formula.
 */
final class RefundLineItem
{
    use JsonStringableTrait;

    /**
     * @param string $uniqueId The unique ID of the original line item being reduced.
     * @param float $returnedQuantity The number of units being physically returned.
     * @param float $unitPriceReduction The price reduction applied to each remaining (non-returned) unit.
     */
    public function __construct(
        public readonly string $uniqueId,
        public readonly float $returnedQuantity,
        public readonly float $unitPriceReduction,
    ) {
    }
}
