<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\LineItem;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemProrationService;
use WeArePlanet\PluginCore\Log\LoggerInterface;

class LineItemProrationServiceTest extends TestCase
{
    private MockObject|LoggerInterface $logger;
    private LineItemProrationService $service;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new LineItemProrationService($this->logger);
    }

    private function makeItem(string $uniqueId, float $amountIncludingTax, float $quantity = 1.0): LineItem
    {
        $item = new LineItem();
        $item->uniqueId = $uniqueId;
        $item->sku = $uniqueId;
        $item->name = $uniqueId;
        $item->quantity = $quantity;
        $item->amountIncludingTax = $amountIncludingTax;
        $item->unitPriceIncludingTax = $amountIncludingTax / $quantity;

        return $item;
    }

    public function testReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame([], $this->service->scaleItems([], 100.00, 'EUR'));
    }

    public function testReturnsItemsUnchangedWhenCurrentTotalIsZero(): void
    {
        $item = $this->makeItem('a', 0.0);

        $result = $this->service->scaleItems([$item], 50.00, 'EUR');

        $this->assertSame([$item], $result);
    }

    public function testScalesASingleItemDown(): void
    {
        $item = $this->makeItem('a', 100.00, quantity: 4);

        $result = $this->service->scaleItems([$item], 50.00, 'EUR');

        $this->assertCount(1, $result);
        $this->assertSame(50.00, $result[0]->amountIncludingTax);
        $this->assertSame(12.5, $result[0]->unitPriceIncludingTax);
    }

    public function testScalesASingleItemUp(): void
    {
        $item = $this->makeItem('a', 50.00, quantity: 2);

        $result = $this->service->scaleItems([$item], 100.00, 'EUR');

        $this->assertSame(100.00, $result[0]->amountIncludingTax);
        $this->assertSame(50.0, $result[0]->unitPriceIncludingTax);
    }

    /**
     * The classic "split ten by three" drift case: three identical items
     * summing to 30.00, scaled down to a target of 10.00 — a factor of
     * exactly 1/3, which does not divide evenly at 2 decimal places.
     */
    public function testAbsorbsRoundingDriftIntoTheFirstItemForEur(): void
    {
        $items = [
            $this->makeItem('a', 10.00),
            $this->makeItem('b', 10.00),
            $this->makeItem('c', 10.00),
        ];

        $result = $this->service->scaleItems($items, 10.00, 'EUR');

        $this->assertCount(3, $result);

        $sum = $result[0]->amountIncludingTax + $result[1]->amountIncludingTax + $result[2]->amountIncludingTax;
        $this->assertSame(10.00, $sum);

        $this->assertSame(3.34, $result[0]->amountIncludingTax);
        $this->assertSame(3.33, $result[1]->amountIncludingTax);
        $this->assertSame(3.33, $result[2]->amountIncludingTax);

        // unitPriceIncludingTax must be re-derived to match the new amount (quantity 1 each).
        $this->assertSame(3.34, $result[0]->unitPriceIncludingTax);
        $this->assertSame(3.33, $result[1]->unitPriceIncludingTax);
        $this->assertSame(3.33, $result[2]->unitPriceIncludingTax);
    }

    /**
     * Same drift scenario, but for a 0-decimal currency (JPY), to confirm
     * the rounding precision follows the currency, not a hardcoded 2 decimals.
     */
    public function testAbsorbsRoundingDriftForZeroDecimalCurrency(): void
    {
        $items = [
            $this->makeItem('a', 1000.0),
            $this->makeItem('b', 1000.0),
            $this->makeItem('c', 1000.0),
        ];

        $result = $this->service->scaleItems($items, 1000.0, 'JPY');

        $sum = $result[0]->amountIncludingTax + $result[1]->amountIncludingTax + $result[2]->amountIncludingTax;
        $this->assertSame(1000.0, $sum);

        $this->assertSame(334.0, $result[0]->amountIncludingTax);
        $this->assertSame(333.0, $result[1]->amountIncludingTax);
        $this->assertSame(333.0, $result[2]->amountIncludingTax);
    }

    public function testDoesNotMutateTheOriginalItems(): void
    {
        $item = $this->makeItem('a', 100.00, quantity: 4);

        $this->service->scaleItems([$item], 50.00, 'EUR');

        $this->assertSame(100.00, $item->amountIncludingTax);
        $this->assertSame(25.0, $item->unitPriceIncludingTax);
    }

    public function testProportionsAreMaintainedAcrossDifferentlyPricedItems(): void
    {
        // Original cart: 80.00 + 20.00 = 100.00 (80/20 split).
        // Captured half (50.00) should preserve that same 80/20 split.
        $items = [
            $this->makeItem('a', 80.00),
            $this->makeItem('b', 20.00),
        ];

        $result = $this->service->scaleItems($items, 50.00, 'EUR');

        $this->assertSame(40.00, $result[0]->amountIncludingTax);
        $this->assertSame(10.00, $result[1]->amountIncludingTax);
    }
}
