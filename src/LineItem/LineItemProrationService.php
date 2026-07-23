<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\LineItem;

use WeArePlanet\PluginCore\Currency\CurrencyRoundingService;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;

/**
 * Proportionally scales a set of line items down (or up) to a target total.
 *
 * Typical use case: a partial invoice capture only covers a fraction of the
 * original transaction, and the shop needs a line item breakdown for that
 * fraction — reflecting the same relative proportions as the original cart
 * — to build the capture payload (e.g. a `CaptureRequest`).
 *
 * The returned line items are new objects (cloned from the input); the
 * originals passed in are never mutated.
 */
#[LogContext(domain: 'transaction', subdomain: 'completion')]
final class LineItemProrationService
{
    use DomainLoggerTrait;

    public function __construct(LoggerInterface $logger)
    {
        $this->initializeLogger($logger);
    }

    /**
     * Scales $items so their amounts sum to exactly $targetAmount, preserving
     * their original relative proportions.
     *
     * If the items currently sum to 0, they are returned unchanged (there is
     * no meaningful ratio to scale by). Otherwise, each item's amount is
     * scaled by `$targetAmount / $currentTotal` and rounded to the
     * currency's decimal precision; any rounding drift left over after
     * scaling every item is absorbed into the first item, so the returned
     * items always sum to exactly $targetAmount.
     *
     * @param LineItem[] $items The original line items to scale.
     * @param float $targetAmount The total the scaled items must sum to.
     * @param string $currencyCode A 3-letter ISO 4217 currency code (e.g. 'EUR', 'JPY').
     * @return LineItem[] The scaled line items (new objects; $items is untouched).
     */
    public function scaleItems(array $items, float $targetAmount, string $currencyCode): array
    {
        if ($items === []) {
            return [];
        }

        $currentTotal = 0.0;
        foreach ($items as $item) {
            $currentTotal += $item->amountIncludingTax;
        }

        if ($currentTotal === 0.0) {
            return $items;
        }

        $factor = $targetAmount / $currentTotal;

        $scaledItems = [];
        foreach ($items as $item) {
            $scaledItem = clone $item;
            $scaledItem->amountIncludingTax = CurrencyRoundingService::round($scaledItem->amountIncludingTax * $factor, $currencyCode);
            $scaledItem->unitPriceIncludingTax = UnitPriceCalculator::deriveUnitPrice($scaledItem->amountIncludingTax, $scaledItem->quantity);
            $scaledItems[] = $scaledItem;
        }

        // Drift Absorption: rounding each item individually can leave the sum
        // a cent or two off the target. Fold that difference into the first
        // item so the returned items always sum to exactly $targetAmount.
        $appliedAmount = 0.0;
        foreach ($scaledItems as $scaledItem) {
            $appliedAmount += $scaledItem->amountIncludingTax;
        }

        $roundingDifference = CurrencyRoundingService::round($targetAmount - $appliedAmount, $currencyCode);

        if ($roundingDifference !== 0.0) {
            $this->logger->debug('Absorbing proration rounding drift into the first line item.', [
                'targetAmount' => $targetAmount,
                'appliedAmount' => $appliedAmount,
                'roundingDifference' => $roundingDifference,
            ]);

            $firstItem = $scaledItems[0];
            $firstItem->amountIncludingTax = CurrencyRoundingService::round($firstItem->amountIncludingTax + $roundingDifference, $currencyCode);
            $firstItem->unitPriceIncludingTax = UnitPriceCalculator::deriveUnitPrice($firstItem->amountIncludingTax, $firstItem->quantity);
        }

        return $scaledItems;
    }
}
