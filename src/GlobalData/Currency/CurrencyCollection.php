<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\GlobalData\Currency;

use WeArePlanet\PluginCore\SharedKernel\AbstractCollection;

/**
 * Strictly typed, iterable collection of {@see Currency} entities.
 *
 * @extends AbstractCollection<Currency>
 */
final class CurrencyCollection extends AbstractCollection
{
    public function __construct(Currency ...$items)
    {
        $this->items = array_values($items);
    }

    /**
     * Finds the currency with the given ISO 4217 currency code.
     *
     * @param string $currencyCode The currency code to look up (e.g. 'CHF'), matched
     *        case-insensitively.
     * @return Currency|null The matching currency, or null when this collection has none.
     */
    public function findByCurrencyCode(string $currencyCode): ?Currency
    {
        foreach ($this->items as $currency) {
            if (strcasecmp($currency->currencyCode, $currencyCode) === 0) {
                return $currency;
            }
        }

        return null;
    }
}
