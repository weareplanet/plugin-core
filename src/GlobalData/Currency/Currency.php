<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\GlobalData\Currency;

use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * Value Object representing one currency the WeArePlanet Portal supports.
 *
 * This is global reference data — the same currencies are available to every
 * space, so there is nothing space-specific to read here. It answers "which
 * currencies can a transaction be created in, and how are their amounts
 * formatted", which is what a plugin needs when building a currency picker or
 * validating a shop's configured currency against what the WeArePlanet Portal accepts.
 *
 * Instances are immutable snapshots of what the API reported at read time.
 */
readonly class Currency
{
    use JsonStringableTrait;

    /**
     * @param string $currencyCode The ISO 4217 alphabetic currency code (e.g. 'CHF', 'EUR').
     * @param int $fractionDigits The number of decimal places this currency's amounts are
     *        expressed with. Most currencies use 2, some (e.g. 'JPY') use 0, and a few
     *        (e.g. 'BHD') use 3 — see {@see \WeArePlanet\PluginCore\GlobalData\Currency\CurrencyRoundingService}
     *        for rounding an amount to the correct precision.
     * @param string $name The English name of the currency (e.g. 'Swiss Franc').
     * @param int $numericCode The ISO 4217 numeric currency code.
     */
    public function __construct(
        public string $currencyCode,
        public int $fractionDigits,
        public string $name,
        public int $numericCode,
    ) {
    }
}
