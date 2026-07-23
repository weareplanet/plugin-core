<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Currency;

/**
 * Rounds monetary amounts to the number of decimal places a currency actually uses.
 *
 * Most ISO 4217 currencies use 2 decimal places, but this is not universal:
 * some (e.g. JPY, KRW) have no minor unit at all, while others (e.g. BHD, KWD)
 * use 3. Rounding a JPY amount to 2 decimals produces fractions of a Yen that
 * don't exist and that gateways will reject; rounding a KWD amount to 2
 * decimals silently discards a valid third digit (fils).
 */
final class CurrencyRoundingService
{
    /**
     * Currencies with no minor unit (0 decimal places).
     *
     * @var list<string>
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * Currencies whose minor unit has 3 decimal places.
     *
     * @var list<string>
     */
    private const THREE_DECIMAL_CURRENCIES = [
        'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND',
    ];

    /**
     * The number of decimal places all other (unlisted) currencies use.
     */
    private const DEFAULT_DECIMALS = 2;

    /**
     * Returns the number of decimal places the given ISO 4217 currency code uses.
     *
     * @param string $currencyCode A 3-letter ISO 4217 currency code (e.g. 'EUR', 'JPY').
     */
    public static function decimalsFor(string $currencyCode): int
    {
        $code = strtoupper($currencyCode);

        if (in_array($code, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return 0;
        }

        if (in_array($code, self::THREE_DECIMAL_CURRENCIES, true)) {
            return 3;
        }

        return self::DEFAULT_DECIMALS;
    }

    /**
     * Rounds an amount to the correct number of decimal places for the given currency.
     *
     * @param float $amount The amount to round.
     * @param string $currencyCode A 3-letter ISO 4217 currency code (e.g. 'EUR', 'JPY').
     */
    public static function round(float $amount, string $currencyCode): float
    {
        return round($amount, self::decimalsFor($currencyCode));
    }

    /**
     * Whether two amounts are equal once both are rounded to the currency's
     * decimal precision.
     *
     * Useful for comparing a locally calculated total against a gateway-reported
     * amount without false mismatches caused by binary floating-point
     * representation (e.g. 19.999999999998 vs 20.0).
     *
     * @param float $amount1 The first amount to compare.
     * @param float $amount2 The second amount to compare.
     * @param string $currencyCode A 3-letter ISO 4217 currency code (e.g. 'EUR', 'JPY').
     */
    public static function areAmountsEqual(float $amount1, float $amount2, string $currencyCode): bool
    {
        return abs(self::round($amount1, $currencyCode) - self::round($amount2, $currencyCode)) < 0.000001;
    }
}
