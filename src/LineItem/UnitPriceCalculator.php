<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\LineItem;

/**
 * Derives a {@see LineItem} per-unit price from a total amount and quantity.
 *
 * Consumers should always prefer reading {@see LineItem::$unitPriceIncludingTax}
 * directly over deriving it via division, since floating-point division
 * introduces rounding errors that cause the gateway API to reject requests.
 * This class exists for the rare, deliberate case where a per-unit price
 * must be reconstructed after the total has already been recalculated (e.g.
 * after proportionally scaling a line item's amount) and no authoritative
 * unit price is available.
 */
final class UnitPriceCalculator
{
    /**
     * Safely derives a per-unit price from a total amount and quantity.
     *
     * Returns 0.0 when $quantity is 0 to avoid a division-by-zero error.
     *
     * @param float $amountIncludingTax The total line amount including tax.
     * @param float $quantity The quantity the amount is spread across.
     */
    public static function deriveUnitPrice(float $amountIncludingTax, float $quantity): float
    {
        if ($quantity === 0.0) {
            return 0.0;
        }

        return $amountIncludingTax / $quantity;
    }
}
