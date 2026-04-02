<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\LineItem;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\LineItem\LineItem;

class LineItemTest extends TestCase
{
    public function testToString(): void
    {
        $item = new LineItem();
        $item->uniqueId = 'ITEM-1';
        $item->sku = 'SKU-001';
        $item->name = 'Product';
        $item->quantity = 2.0;
        $item->amountIncludingTax = 10.00;

        $json = (string) $item;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertEquals('ITEM-1', $decoded['uniqueId']);
        $this->assertEquals('SKU-001', $decoded['sku']);
        $this->assertEquals('Product', $decoded['name']);
        $this->assertEquals(2.0, $decoded['quantity']);
        $this->assertEquals(10.00, $decoded['amountIncludingTax']);
    }
}
