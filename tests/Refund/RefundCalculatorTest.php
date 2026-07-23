<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Refund;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\Refund\LineItem\RefundLineItem;
use WeArePlanet\PluginCore\Refund\RefundCalculator;

class RefundCalculatorTest extends TestCase
{
    private function makeLineItem(float $quantity, float $unitPriceIncludingTax): LineItem
    {
        $item = new LineItem();
        $item->uniqueId = 'sku-123';
        $item->quantity = $quantity;
        $item->unitPriceIncludingTax = $unitPriceIncludingTax;
        $item->amountIncludingTax = $quantity * $unitPriceIncludingTax;

        return $item;
    }

    public function testFullyReturnedItemUsesOnlyTheUnitPrice(): void
    {
        $originalItem = $this->makeLineItem(quantity: 2, unitPriceIncludingTax: 150.00);
        $reduction = new RefundLineItem('sku-123', returnedQuantity: 2, unitPriceReduction: 0.0);

        $total = RefundCalculator::calculateReduction($originalItem, $reduction);

        $this->assertSame(300.00, $total);
    }

    public function testPriceReductionWithNoUnitsReturned(): void
    {
        // 2 units, no stock returned, but a 10.00 per-unit price reduction on
        // the 2 remaining units => 20.00 total, matching docs/Refund/README.md.
        $originalItem = $this->makeLineItem(quantity: 2, unitPriceIncludingTax: 150.00);
        $reduction = new RefundLineItem('sku-123', returnedQuantity: 0, unitPriceReduction: 10.00);

        $total = RefundCalculator::calculateReduction($originalItem, $reduction);

        $this->assertSame(20.00, $total);
    }

    public function testPartialReturnWithNoAdditionalReduction(): void
    {
        // 1 of 2 units returned at full price, remaining unit untouched.
        $originalItem = $this->makeLineItem(quantity: 2, unitPriceIncludingTax: 150.00);
        $reduction = new RefundLineItem('sku-123', returnedQuantity: 1, unitPriceReduction: 0.0);

        $total = RefundCalculator::calculateReduction($originalItem, $reduction);

        $this->assertSame(150.00, $total);
    }

    public function testCombinedReturnAndPriceReduction(): void
    {
        // 1 of 3 units returned at 25.00, remaining 2 units get a 5.00 reduction each.
        $originalItem = $this->makeLineItem(quantity: 3, unitPriceIncludingTax: 25.00);
        $reduction = new RefundLineItem('sku-123', returnedQuantity: 1, unitPriceReduction: 5.00);

        $total = RefundCalculator::calculateReduction($originalItem, $reduction);

        $this->assertSame(35.00, $total); // (1 * 25.00) + (2 * 5.00)
    }

    public function testZeroQuantityAndZeroReductionYieldsZero(): void
    {
        $originalItem = $this->makeLineItem(quantity: 1, unitPriceIncludingTax: 50.00);
        $reduction = new RefundLineItem('sku-123', returnedQuantity: 0, unitPriceReduction: 0.0);

        $total = RefundCalculator::calculateReduction($originalItem, $reduction);

        $this->assertSame(0.0, $total);
    }
}
