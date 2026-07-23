<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\SharedKernel\Url;
use WeArePlanet\PluginCore\Transaction\TransactionContext;

class TransactionContextTest extends TestCase
{
    public function testNewFieldsCanBeSet(): void
    {
        $context = new TransactionContext();
        $context->invoiceMerchantReference = 'INV-001';
        $context->metaData = ['source' => 'checkout'];
        $context->allowedPaymentMethodConfigurations = [1, 2, 3];

        $this->assertSame('INV-001', $context->invoiceMerchantReference);
        $this->assertSame(['source' => 'checkout'], $context->metaData);
        $this->assertSame([1, 2, 3], $context->allowedPaymentMethodConfigurations);
    }

    public function testNewFieldsDefaultToEmptyValues(): void
    {
        $context = new TransactionContext();

        $this->assertNull($context->invoiceMerchantReference);
        $this->assertSame([], $context->metaData);
        $this->assertSame([], $context->allowedPaymentMethodConfigurations);
    }

    public function testSanitizeLeavesNullShippingMethodAsNull(): void
    {
        $context = new TransactionContext();

        $context->sanitize();

        $this->assertNull($context->shippingMethod);
    }

    public function testSanitizeLeavesShortShippingMethodUntouched(): void
    {
        $context = new TransactionContext();
        $context->shippingMethod = 'Standard Shipping';

        $context->sanitize();

        $this->assertSame('Standard Shipping', $context->shippingMethod);
    }

    public function testSanitizeTruncatesShippingMethod(): void
    {
        $context = new TransactionContext();
        $context->shippingMethod = str_repeat('a', 250);

        $context->sanitize();

        $this->assertSame(str_repeat('a', 200), $context->shippingMethod);
    }
    public function testToString(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->merchantReference = 'ORDER-123';
        $context->customerId = 'CUST-001';
        $context->currencyCode = 'EUR';
        $context->language = 'en-US';
        $context->successUrl = new Url('http://localhost/success');
        $context->failedUrl = new Url('http://localhost/failed');
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
