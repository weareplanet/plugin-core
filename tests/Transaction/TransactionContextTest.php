<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Transaction\TransactionContext;
use WeArePlanet\PluginCore\Address\Address;

class TransactionContextTest extends TestCase
{
    public function testToString(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->merchantReference = 'ORDER-123';
        $context->customerId = 'CUST-001';
        $context->currencyCode = 'EUR';
        $context->language = 'en-US';
        $context->successUrl = 'http://localhost/success';
        $context->failedUrl = 'http://localhost/failed';
        $context->expectedGrandTotal = 100.00;

        $billing = new Address();
        $billing->city = 'Test City';
        $billing->country = 'DE';
        $context->billingAddress = $billing;

        $json = (string) $context;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertEquals(1, $decoded['spaceId']);
        $this->assertEquals('ORDER-123', $decoded['merchantReference']);
        $this->assertEquals('CUST-001', $decoded['customerId']);
        $this->assertEquals('EUR', $decoded['currencyCode']);
        $this->assertArrayHasKey('billingAddress', $decoded);
    }
}
