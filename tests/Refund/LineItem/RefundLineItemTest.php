<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Refund\LineItem;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Refund\LineItem\RefundLineItem;

class RefundLineItemTest extends TestCase
{
    public function testToString(): void
    {
        $item = new RefundLineItem('ITEM-1', 2.0, 10.00);

        $json = (string) $item;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertEquals('ITEM-1', $decoded['uniqueId']);
        $this->assertEquals(2.0, $decoded['returnedQuantity']);
        $this->assertEquals(10.00, $decoded['unitPriceReduction']);
    }
}
