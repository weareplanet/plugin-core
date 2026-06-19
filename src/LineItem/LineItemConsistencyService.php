<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\LineItem;

use WeArePlanet\PluginCore\LineItem\Exception\LineItemConsistencyException;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Settings\Settings;

class LineItemConsistencyService
{
    private const ADJUSTMENT_NAME = 'Rounding Adjustment';
    private const ADJUSTMENT_SKU = 'rounding-adjustment';
    private const MAX_ALLOWED_DIFFERENCE = 0.10;

    public function __construct(
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Calculates the sum of line items using the configured rounding strategy.
     *
     * Differences in calculation often arise between shops and payment gateways
     * due to when rounding is applied (at the line-item level vs. the grand total).
     * This method ensures we use the strategy that most closely matches the shop's
     * internal calculation to minimize rounding adjustments later.
     *
     * @param LineItem[] $lineItems The line items to sum.
     * @return float The calculated total sum, rounded to 2 decimal places.
     */
    private function calculateSum(array $lineItems): float
    {
        $strategy = $this->settings->getLineItemRoundingStrategy();

        $this->logger->debug("Calculating sum using strategy: " . $strategy->value);

        $sum = 0.0;
        foreach ($lineItems as $item) {
            if ($strategy === RoundingStrategy::BY_LINE_ITEM) {
                // Rounding each line item individually avoids large discrepancies
                // in shops that calculate tax per item.
                $sum += round($item->amountIncludingTax, 2);
            } else {
                // Using raw totals is preferred for shops that round only at the final sum.
                $sum += $item->amountIncludingTax;
            }
        }

        $result = round($sum, 2);
        $this->logger->debug("Calculated total: $result");

        return $result;
    }


    /**
     * Ensures consistency between the shop's expected total and the line item sum.
     *
     * Payment gateways require the sum of line items to exactly match the transaction
     * amount. If a discrepancy exists (usually due to rounding or tax calculation
     * differences), we add a "Rounding Adjustment" line item.
     *
     * @param LineItem[] $lineItems The original line items from the shop.
     * @param float $expectedTotal The grand total the shop expects the customer to pay.
     * @param string $currencyCode The currency code (for context, unused for calculation).
     * @param int|null $spaceId The unique space identifier for log tracing.
     * @param int|null $transactionId The unique transaction identifier for log tracing.
     * @return LineItem[] The list of line items, potentially including an adjustment.
     * @throws LineItemConsistencyException If the discrepancy is too large to safely auto-correct.
     */
    public function ensureConsistency(
        array $lineItems,
        float $expectedTotal,
        string $currencyCode,
        ?int $spaceId = null,
        ?int $transactionId = null,
    ): array {
        $calculatedTotal = $this->calculateSum($lineItems);
        $difference = $expectedTotal - $calculatedTotal;

        // Perfect Match: No action needed.
        if (abs($difference) < 0.000001) {
            return $lineItems;
        }

        // Feature Disabled: We abort to prevent processing a transaction that
        // the gateway will likely reject due to a total mismatch.
        if (!$this->settings->isLineItemConsistencyEnabled()) {
            $msg = sprintf(
                "Line item discrepancy of %.2f detected (Expected: %.2f, Calculated: %.2f). Line item consistency enforcement is DISABLED. Proceeding with mismatched totals. The WeArePlanet API will likely reject this request or hide payment methods.",
                $difference,
                $expectedTotal,
                $calculatedTotal,
            );
            $this->logger->warning($msg, [
                'expectedAmount' => $expectedTotal,
                'calculatedAmount' => $calculatedTotal,
                'difference' => round($difference, 2),
                'spaceId' => $spaceId,
                'transactionId' => $transactionId,
            ]);
            throw new LineItemConsistencyException("Mismatch found ($difference) but auto-correction is DISABLED.");
        }

        // Safety Guard: A difference larger than 0.10 (10 cents) usually indicates
        // a configuration error (e.g., missing tax, wrong currency) rather than
        // a simple rounding error. Auto-correcting large amounts is risky for accounting.
        if (abs($difference) > self::MAX_ALLOWED_DIFFERENCE) {
            $msg = sprintf("Rounding difference (%f) exceeds safety threshold (%f). Aborting.", $difference, self::MAX_ALLOWED_DIFFERENCE);
            $this->logger->error($msg);
            throw new LineItemConsistencyException($msg);
        }

        // Fix it: Append a system-generated line item to balance the total.
        $msg = sprintf(
            "Line item discrepancy detected. Expected: %.2f, Calculated: %.2f, Difference: %.2f. Appending 'Rounding Adjustment' line item to satisfy gateway validation.",
            $expectedTotal,
            $calculatedTotal,
            $difference,
        );
        $this->logger->info($msg, [
            'expectedAmount' => $expectedTotal,
            'calculatedAmount' => $calculatedTotal,
            'difference' => round($difference, 2),
            'spaceId' => $spaceId,
            'transactionId' => $transactionId,
        ]);

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
     * Sanitizes negative quantities or discounts to ensure a non-negative total.
     *
     * Most payment gateways do not support transactions with a total amount <= 0.
     * This occurs when discounts exceed the value of the products (e.g. combined gift cards).
     * We cap the discounts proportionally to keep the total at exactly zero, allowing
     * the transaction to be created as "Free" in the portal.
     *
     * @param LineItem[] $lineItems
     * @return LineItem[] The sanitized list (cloned to avoid side effects).
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

        // If total is non-negative (within float epsilon), nothing to do.
        if ($totalSum >= -0.00000001) {
            return $lineItems;
        }

        // If no discounts found to heal, we cannot fix the negative total here.
        if (abs($discountSum) < 0.00000001) {
            return $lineItems;
        }

        $this->logger->warning("Transaction total was negative. Auto-capped discounts to equal product value.");

        /**
         * The capping factor calculation:
         * We want NewTotalSum = 0.
         * NewTotalSum = (TotalSum - DiscountSum) + NewDiscountSum.
         * 0 = (TotalSum - DiscountSum) + (DiscountSum * Factor).
         * -(TotalSum - DiscountSum) = DiscountSum * Factor.
         * Factor = (DiscountSum - TotalSum) / DiscountSum.
         */
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
}
