<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Refund;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Refund\Refund;
use WeArePlanet\PluginCore\Refund\State;

class RefundTest extends TestCase
{
    public function testToString(): void
    {
        $refund = new Refund();
        $refund->id = 500;
        $refund->amount = 10.50;
        $refund->state = State::SUCCESSFUL;
        $refund->transactionId = 1001;
        $refund->externalId = 'REF-001';

        $json = (string) $refund;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertEquals(500, $decoded['id']);
        $this->assertEquals(10.50, $decoded['amount']);
        $this->assertEquals(1001, $decoded['transactionId']);
        $this->assertEquals('REF-001', $decoded['externalId']);
        $this->assertArrayHasKey('state', $decoded);
    }
}
