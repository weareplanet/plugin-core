<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\GlobalData\Currency;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\GlobalData\Currency\Currency;
use WeArePlanet\PluginCore\GlobalData\Currency\CurrencyCollection;

class CurrencyTest extends TestCase
{
    public function testCollectionIsCountableAndIterable(): void
    {
        $collection = new CurrencyCollection(
            new Currency('CHF', 2, 'Swiss Franc', 756),
            new Currency('EUR', 2, 'Euro', 978),
        );

        $this->assertCount(2, $collection);
        $this->assertFalse($collection->isEmpty());
        $this->assertSame(['CHF', 'EUR'], array_map(static fn (Currency $c) => $c->currencyCode, [...$collection]));
    }

    public function testCurrencyIsImmutable(): void
    {
        $currency = new Currency('CHF', 2, 'Swiss Franc', 756);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line Intentionally writing to a readonly property.
        $currency->currencyCode = 'EUR';
    }

    public function testFindByCurrencyCodeIsCaseInsensitive(): void
    {
        $chf = new Currency('CHF', 2, 'Swiss Franc', 756);
        $collection = new CurrencyCollection($chf, new Currency('EUR', 2, 'Euro', 978));

        $this->assertSame($chf, $collection->findByCurrencyCode('chf'));
        $this->assertNull($collection->findByCurrencyCode('USD'));
    }
    public function testToString(): void
    {
        $currency = new Currency(
            currencyCode: 'CHF',
            fractionDigits: 2,
            name: 'Swiss Franc',
            numericCode: 756,
        );

        $json = (string) $currency;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertSame('CHF', $decoded['currencyCode']);
        $this->assertSame(2, $decoded['fractionDigits']);
        $this->assertSame('Swiss Franc', $decoded['name']);
        $this->assertSame(756, $decoded['numericCode']);
    }
}
