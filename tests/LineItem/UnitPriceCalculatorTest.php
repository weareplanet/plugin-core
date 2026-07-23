<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\LineItem;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\LineItem\UnitPriceCalculator;

class UnitPriceCalculatorTest extends TestCase
{
    public function testDerivesUnitPriceFromAmountAndQuantity(): void
    {
        $this->assertSame(12.5, UnitPriceCalculator::deriveUnitPrice(50.00, 4));
    }

    public function testReturnsZeroWhenQuantityIsZero(): void
    {
        $this->assertSame(0.0, UnitPriceCalculator::deriveUnitPrice(100.00, 0.0));
    }

    public function testReturnsZeroWhenAmountAndQuantityAreBothZero(): void
    {
        $this->assertSame(0.0, UnitPriceCalculator::deriveUnitPrice(0.0, 0.0));
    }

    public function testHandlesFractionalQuantities(): void
    {
        $this->assertSame(2.0, UnitPriceCalculator::deriveUnitPrice(5.00, 2.5));
    }
}
