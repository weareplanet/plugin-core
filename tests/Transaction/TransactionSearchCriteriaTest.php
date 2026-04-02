<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Transaction\TransactionSearchCriteria;

class TransactionSearchCriteriaTest extends TestCase
{
    public function testToString(): void
    {
        $criteria = new TransactionSearchCriteria(10, 'merchantReference', 'ASC', ['state' => 'AUTHORIZED']);

        $json = (string) $criteria;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertEquals(10, $decoded['limit']);
        $this->assertEquals('merchantReference', $decoded['sortField']);
        $this->assertEquals('ASC', $decoded['sortOrder']);
        $this->assertEquals(['state' => 'AUTHORIZED'], $decoded['filters']);
    }
}
