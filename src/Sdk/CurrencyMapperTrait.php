<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\GlobalData\Currency\Currency;
use WeArePlanet\Sdk\Model\RestCurrency as SdkRestCurrency;

/**
 * Shared mapping trait for SDK RestCurrency objects to domain objects.
 *
 * Every field is a plain scalar on both API versions, so this mapping is
 * identical everywhere — there is no payload-shape difference to absorb.
 */
trait CurrencyMapperTrait
{
    /**
     * Maps an SDK RestCurrency to a domain Currency.
     *
     * @param SdkRestCurrency $sdkCurrency The SDK currency.
     * @return Currency The mapped domain currency.
     */
    protected function mapToCurrency(SdkRestCurrency $sdkCurrency): Currency
    {
        return new Currency(
            currencyCode: (string)$sdkCurrency->getCurrencyCode(),
            fractionDigits: (int)$sdkCurrency->getFractionDigits(),
            name: (string)$sdkCurrency->getName(),
            numericCode: (int)$sdkCurrency->getNumericCode(),
        );
    }
}
