<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\LineItem;

use WeArePlanet\PluginCore\LineItem\Exception\LineItemConsistencyException;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Settings\Settings;

/**
 * Ensures mathematical consistency between shop line items and gateway requirements.
 *
 * Different shop systems use various rounding strategies (per line item vs. total).
 * This service reconciles these differences to prevent gateway rejections due to
 * "Total Mismatch" errors. It also handles edge cases like negative transaction totals
 * caused by aggressive discounting.
 */
class LineItemConsistencyService
{
    private const ADJUSTMENT_NAME = 'Rounding Adjustment';
    private const ADJUSTMENT_SKU = 'rounding-adjustment';
    private const MAX_ALLOWED_DIFFERENCE = 0.10;

    /**
     * @param Settings $settings Configuration for rounding strategies and thresholds.
     * @param LoggerInterface $logger The system logger.
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Reconciles the sum of line items with the expected grand total.
     *
     * If a minor discrepancy is found (typically due to rounding), it adds a
     * "Rounding Adjustment" line item to ensure the gateway receives a perfectly
     * balanced transaction.
     *
     * @param LineItem[] $lineItems The original line items from the shop.
     * @param float $expectedTotal The grand total calculated by the shop.
     * @param string $currencyCode The currency of the transaction.
     * @return LineItem[] The consistent list of line items.
     * @throws LineItemConsistencyException If the discrepancy is too large to fix safely.
     */
    public function ensureConsistency(array $lineItems, float $expectedTotal, string $currencyCode): array
    {
        $calculatedTotal = $this->calculateSum($lineItems);
        $difference = $expectedTotal - $calculatedTotal;

        // Exact Match Handling
        // If the difference is below the float epsilon, we consider it a perfect match.
        if (abs($difference) < 0.000001) {
            return $lineItems;
        }

        $this->logger->debug("Consistency Mismatch Detected: Shop Total: $expectedTotal, Line Item Sum: $calculatedTotal, Diff: $difference");

        // Feature Toggle Check
        // Some integrations may prefer a hard failure over automatic adjustments.
        if (!$this->settings->isLineItemConsistencyEnabled()) {
            $this->logger->warning("Mismatch found ($difference) but auto-correction is DISABLED.");
            throw new LineItemConsistencyException("Mismatch found ($difference) but auto-correction is DISABLED.");
        }

        // Safety Threshold Validation
        // We limit automatic adjustments to a small amount (e.g. 10 cents).
        // A larger difference usually indicates a genuine calculated bug rather than a rounding issue.
        if (abs($difference) > self::MAX_ALLOWED_DIFFERENCE) {
            $threshold = self::MAX_ALLOWED_DIFFERENCE;
            $this->logger->error("Rounding difference ($difference) exceeds safety threshold ($threshold). Aborting.");
            throw new LineItemConsistencyException("Rounding difference ($difference) exceeds safety threshold ($threshold). Aborting.");
        }

        // Rounding Correction
        // We append a technical fee/discount item to bridge the gap.
        $this->logger->info("Auto-correcting rounding difference of $difference by adding adjustment line item.");

        $adjustmentItem = new LineItem();
        $adjustmentItem->uniqueId = self::ADJUSTMENT_SKU;
        $adjustmentItem->sku = self::ADJUSTMENT_SKU;
        $adjustmentItem->name = self::ADJUSTMENT_NAME;
        $adjustmentItem->quantity = 1;
        $adjustmentItem->amountIncludingTax = round($difference, 2);
        $adjustmentItem->type = LineItem::TYPE_FEE;
        $adjustmentItem->shippingRequired = false;

        $lineItems[] = $adjustmentItem;

        return $lineItems;
    }

    /**
     * Sanitizes discounts to prevent negative transaction totals.
     *
     * Most payment gateways reject transactions with a total < 0. If a shop applies
     * discounts that exceed the product value, this method proportionally caps the
     * discount amounts to bring the total exactly to zero.
     *
     * @param LineItem[] $lineItems Original line items.
     * @return LineItem[] Sanitized items with capped discounts.
     */
    public function sanitizeNegativeLineItems(array $lineItems): array
    {
        $totalSum = 0.0;
        $discountSum = 0.0;

        foreach ($lineItems as $item) {
            $totalSum += $item->amountIncludingTax;
            if ($item->type === LineItem::TYPE_DISCOUNT && $item->amountIncludingTax < 0) {
                $discountSum += $item->amountIncludingTax;
            }
        }

        // Pre-condition: Is the total actually negative?
        if ($totalSum >= -0.00000001) {
            return $lineItems;
        }

        // Pre-condition: Are there any discounts to adjust?
        if (abs($discountSum) < 0.00000001) {
            return $lineItems;
        }

        $this->logger->warning("Transaction total was negative. Auto-capped discounts to equal product value.");

        // Harmonic Adjustment Factor
        // We calculate a multiplier to reduce all negative discounts proportionally
        // until the total sum reaches zero.
        // Formula: NewDiscountSum = -(TotalSum - DiscountSum)
        $factor = ($discountSum - $totalSum) / $discountSum;

        $sanitizedItems = [];
        foreach ($lineItems as $item) {
            $cloned = clone $item;
            if ($cloned->type === LineItem::TYPE_DISCOUNT && $cloned->amountIncludingTax < 0) {
                $cloned->amountIncludingTax = round($cloned->amountIncludingTax * $factor, 8);
            }
            $sanitizedItems[] = $cloned;
        }

        return $sanitizedItems;
    }

    /**
     * Calculates the internal sum of all line items based on configured rounding rules.
     *
     * @param LineItem[] $lineItems The items to sum.
     * @return float The calculated total.
     */
    private function calculateSum(array $lineItems): float
    {
        $strategy = $this->settings->getLineItemRoundingStrategy();

        $this->logger->debug("Calculating sum using strategy: {$strategy->value}");

        $sum = 0.0;
        foreach ($lineItems as $item) {
            // Some shops round each line item price before summing, while others round the final total.
            if ($strategy === RoundingStrategy::BY_LINE_ITEM) {
                $sum += round($item->amountIncludingTax, 2);
            } else {
                $sum += $item->amountIncludingTax;
            }
        }

        $result = round($sum, 2);
        $this->logger->debug("Calculated total: $result");

        return $result;
    }
}
