<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Refund;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Refund\RefundContext;
use WeArePlanet\PluginCore\Refund\Type;

class RefundContextTest extends TestCase
{
    public function testToString(): void
    {
        $context = new RefundContext(1001, 10.50, 'REF-001-REQ', Type::MERCHANT_INITIATED_ONLINE, [['uniqueId' => 'ITEM-1', 'quantity' => 1.0, 'amount' => 10.50]]);

        $json = (string) $context;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertEquals(1001, $decoded['transactionId']);
        $this->assertEquals(10.50, $decoded['amount']);
        $this->assertEquals('REF-001-REQ', $decoded['merchantReference']);
        $this->assertArrayHasKey('type', $decoded);
        $this->assertEquals([['uniqueId' => 'ITEM-1', 'quantity' => 1.0, 'amount' => 10.50]], $decoded['lineItems']);
    }
}
