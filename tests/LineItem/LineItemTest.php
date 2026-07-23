<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\LineItem;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\LineItem\LineItem;

class LineItemTest extends TestCase
{
    public function testSanitizeLeavesShortFieldsUntouched(): void
    {
        $item = new LineItem();
        $item->name = 'Product';
        $item->sku = 'SKU-001';

        $item->sanitize();

        $this->assertSame('Product', $item->name);
        $this->assertSame('SKU-001', $item->sku);
    }

    public function testSanitizeTruncatesName(): void
    {
        $item = new LineItem();
        $item->name = str_repeat('a', 200);
        $item->sku = 'SKU-001';

        $item->sanitize();

        $this->assertSame(str_repeat('a', 150), $item->name);
    }

    public function testSanitizeTruncatesSku(): void
    {
        $item = new LineItem();
        $item->name = 'Product';
        $item->sku = str_repeat('b', 250);

        $item->sanitize();

        $this->assertSame(str_repeat('b', 200), $item->sku);
    }
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
