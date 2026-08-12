<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\GlobalData\PaymentConnector;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\GlobalData\PaymentConnector\PaymentConnector;
use WeArePlanet\PluginCore\GlobalData\PaymentConnector\PaymentConnectorCollection;

class PaymentConnectorTest extends TestCase
{
    public function testToString(): void
    {
        $connector = new PaymentConnector(
            id: 31,
            name: new LocalizedString(['en-US' => 'Visa Acquiring']),
            paymentMethodId: 88,
            processorId: 12,
            primaryRiskTaker: 'MERCHANT',
            supportedCurrencies: ['CHF', 'EUR'],
            supportedCustomersPresences: ['VIRTUAL_PRESENT'],
            supportedFeatureIds: [1, 2],
            deprecated: false,
        );

        $json = (string) $connector;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertSame(31, $decoded['id']);
        $this->assertSame(88, $decoded['paymentMethodId']);
        $this->assertSame(12, $decoded['processorId']);
        $this->assertSame('MERCHANT', $decoded['primaryRiskTaker']);
        $this->assertSame(['CHF', 'EUR'], $decoded['supportedCurrencies']);
        $this->assertSame(['VIRTUAL_PRESENT'], $decoded['supportedCustomersPresences']);
        $this->assertSame([1, 2], $decoded['supportedFeatureIds']);
        $this->assertFalse($decoded['deprecated']);
        $this->assertSame('Visa Acquiring', $decoded['name']['en-US']);
    }

    public function testOptionalPropertiesDefaultToNullOrEmpty(): void
    {
        $connector = new PaymentConnector(31, new LocalizedString('Visa Acquiring'));

        $this->assertNull($connector->paymentMethodId);
        $this->assertNull($connector->processorId);
        $this->assertNull($connector->primaryRiskTaker);
        $this->assertSame([], $connector->supportedCurrencies);
        $this->assertSame([], $connector->supportedCustomersPresences);
        $this->assertSame([], $connector->supportedFeatureIds);
        $this->assertFalse($connector->deprecated);
        $this->assertNull($connector->deprecationReason);
    }

    public function testConnectorIsImmutable(): void
    {
        $connector = new PaymentConnector(31, new LocalizedString('Visa Acquiring'));

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line Intentionally writing to a readonly property.
        $connector->id = 32;
    }

    public function testFindByIdReturnsTheMatchingConnector(): void
    {
        $visa = new PaymentConnector(31, new LocalizedString('Visa Acquiring'));
        $collection = new PaymentConnectorCollection($visa, new PaymentConnector(42, new LocalizedString('PayPal')));

        $this->assertSame($visa, $collection->findById(31));
        $this->assertNull($collection->findById(99));
    }
}
