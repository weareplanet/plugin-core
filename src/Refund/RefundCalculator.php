<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund;

use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\Refund\LineItem\RefundLineItem;

/**
 * Calculates the expected refund amount implied by a line item reduction.
 *
 * Platforms should use {@see calculateReduction()} to compute the expected
 * total for each line item reduction *before* submitting a `RefundContext`,
 * so the amount they send as `RefundContext::$amount` (or its sum across
 * multiple reductions) matches exactly what {@see RefundService} will
 * calculate itself during validation — avoiding a rejected "Consistency
 * Error" refund request.
 */
final class RefundCalculator
{
    /**
     * Calculates the total reduction amount for a single line item.
     *
     * Formula: Total Reduction = (Quantity Returned * Unit Price) +
     * (Remaining Quantity * Unit Price Reduction).
     *
     * Always read the per-unit price from {@see LineItem::$unitPriceIncludingTax}
     * — never derive it by dividing `amountIncludingTax` by `quantity`,
     * which introduces floating-point rounding errors that cause the
     * gateway API to reject the refund. See docs/3-Post-Payment/Refund.md for the
     * full explanation.
     *
     * @param LineItem $originalItem The original (pre-refund) line item being reduced.
     * @param RefundLineItem $reduction The requested reduction for this line item.
     * @return float The total reduction amount for this line item.
     */
    public static function calculateReduction(LineItem $originalItem, RefundLineItem $reduction): float
    {
        $remainingQuantity = $originalItem->quantity - $reduction->returnedQuantity;

        return ($reduction->returnedQuantity * $originalItem->unitPriceIncludingTax)
            + ($remainingQuantity * $reduction->unitPriceReduction);
    }
}
