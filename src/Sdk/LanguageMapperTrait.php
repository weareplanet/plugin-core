<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\GlobalData\Language\Language;
use WeArePlanet\Sdk\Model\RestLanguage as SdkRestLanguage;

/**
 * Shared mapping trait for SDK RestLanguage objects to domain objects.
 *
 * Every field is a plain scalar on both API versions, so this mapping is
 * identical everywhere — there is no payload-shape difference to absorb. In
 * particular, `name` is a plain, non-localized string on both API versions (the
 * language's English name), unlike the localized `name` on
 * {@see \WeArePlanet\PluginCore\GlobalData\LabelDescriptor\LabelDescriptor} and
 * {@see \WeArePlanet\PluginCore\GlobalData\PaymentConnector\PaymentConnector}.
 */
trait LanguageMapperTrait
{
    /**
     * Maps an SDK RestLanguage to a domain Language.
     *
     * @param SdkRestLanguage $sdkLanguage The SDK language.
     * @return Language The mapped domain language.
     */
    protected function mapToLanguage(SdkRestLanguage $sdkLanguage): Language
    {
        return new Language(
            iso2Code: (string)$sdkLanguage->getIso2Code(),
            ietfCode: (string)$sdkLanguage->getIetfCode(),
            iso3Code: (string)$sdkLanguage->getIso3Code(),
            name: (string)$sdkLanguage->getName(),
            countryCode: $sdkLanguage->getCountryCode(),
            pluralExpression: $sdkLanguage->getPluralExpression(),
            primaryOfGroup: (bool)$sdkLanguage->getPrimaryOfGroup(),
        );
    }
}
