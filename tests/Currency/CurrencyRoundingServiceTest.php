<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Currency;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Currency\CurrencyRoundingService;

class CurrencyRoundingServiceTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function decimalsProvider(): array
    {
        return [
            'JPY has 0 decimals' => ['JPY', 0],
            'KRW has 0 decimals' => ['KRW', 0],
            'lowercase jpy is treated the same as JPY' => ['jpy', 0],
            'KWD has 3 decimals' => ['KWD', 3],
            'BHD has 3 decimals' => ['BHD', 3],
            'EUR defaults to 2 decimals' => ['EUR', 2],
            'CHF defaults to 2 decimals' => ['CHF', 2],
            'USD defaults to 2 decimals' => ['USD', 2],
            'unknown currency code defaults to 2 decimals' => ['XYZ', 2],
        ];
    }

    #[DataProvider('decimalsProvider')]
    public function testDecimalsFor(string $currencyCode, int $expectedDecimals): void
    {
        $this->assertSame($expectedDecimals, CurrencyRoundingService::decimalsFor($currencyCode));
    }

    /**
     * @return array<string, array{0: float, 1: string, 2: float}>
     */
    public static function roundProvider(): array
    {
        return [
            'JPY rounds to whole units' => [1500.75, 'JPY', 1501.0],
            'KRW rounds to whole units' => [999.5, 'KRW', 1000.0],
            'KWD rounds to 3 decimals' => [10.12345, 'KWD', 10.123],
            'BHD rounds to 3 decimals' => [10.1256, 'BHD', 10.126],
            'EUR rounds to 2 decimals' => [10.126, 'EUR', 10.13],
            'CHF rounds to 2 decimals' => [10.994, 'CHF', 10.99],
        ];
    }

    #[DataProvider('roundProvider')]
    public function testRound(float $amount, string $currencyCode, float $expected): void
    {
        $this->assertSame($expected, CurrencyRoundingService::round($amount, $currencyCode));
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: string, 3: bool}>
     */
    public static function areAmountsEqualProvider(): array
    {
        return [
            'identical EUR amounts are equal' => [20.00, 20.00, 'EUR', true],
            'EUR amounts differing beyond 2 decimals are equal once rounded' => [20.001, 20.004, 'EUR', true],
            'EUR amounts differing by a full cent are not equal' => [20.00, 20.01, 'EUR', false],
            'floating-point noise below the currency precision is ignored' => [19.999999999998, 20.0, 'EUR', true],
            'JPY amounts differing by less than a whole unit are equal' => [1500.4, 1500.0, 'JPY', true],
            'JPY amounts differing by a whole unit are not equal' => [1500.0, 1501.0, 'JPY', false],
            'KWD amounts differing beyond 3 decimals are equal once rounded' => [10.1231, 10.1234, 'KWD', true],
            'KWD amounts differing by a full fils are not equal' => [10.123, 10.124, 'KWD', false],
        ];
    }

    #[DataProvider('areAmountsEqualProvider')]
    public function testAreAmountsEqual(float $amount1, float $amount2, string $currencyCode, bool $expected): void
    {
        $this->assertSame($expected, CurrencyRoundingService::areAmountsEqual($amount1, $amount2, $currencyCode));
    }
}
