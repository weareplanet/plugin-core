<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\State;

class TransactionTest extends TestCase
{
    public function testToString(): void
    {
        $transaction = new Transaction();
        $transaction->id = 1001;
        $transaction->spaceId = 1;
        $transaction->merchantReference = 'ORDER-123';
        $transaction->state = State::AUTHORIZED;

        $json = (string) $transaction;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertEquals(1001, $decoded['id']);
        $this->assertEquals(1, $decoded['spaceId']);
        $this->assertEquals('ORDER-123', $decoded['merchantReference']);

        $this->assertArrayHasKey('state', $decoded);
    }
}
