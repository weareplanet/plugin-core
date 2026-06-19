<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\Sdk\Model\FailureReason as SdkFailureReason;

/**
 * Shared mapping for SDK failure reasons.
 *
 * Centralizes the conversion of an SDK FailureReason into the domain
 * {@see LocalizedString} value object so gateways do not duplicate the
 * description/name fallback logic.
 */
trait FailureReasonMapperTrait
{
    /**
     * Maps an SDK FailureReason to a localized domain string.
     *
     * Prefers the localized description map; falls back to the name map when
     * the description is absent.
     */
    protected function mapSdkFailureReason(SdkFailureReason $reason): LocalizedString
    {
        return new LocalizedString($reason->getDescription() ?? $reason->getName());
    }
}
