<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\Charge\Attempt\ChargeAttempt;
use WeArePlanet\PluginCore\Charge\Attempt\Label;
use WeArePlanet\Sdk\Model\ChargeAttempt as SdkChargeAttempt;

/**
 * Shared mapping trait for SDK ChargeAttempt objects to domain objects.
 *
 * Centralizes the conversion of an SDK charge attempt and its labels into the
 * domain {@see ChargeAttempt}, keeping the payload-shape differences between API
 * versions out of the gateways that call it. See
 * {@see \WeArePlanet\PluginCore\Charge\ChargeGatewayInterface}
 * for the resulting portability note on {@see Label::$groupName}.
 */
trait ChargeAttemptMapperTrait
{
    use LabelMapperTrait;

    /**
     * Maps an SDK ChargeAttempt to a domain ChargeAttempt.
     *
     * This API returns the label descriptor's group inline, so
     * {@see Label::$groupName} is populated here.
     *
     * @param SdkChargeAttempt $sdkChargeAttempt The SDK charge attempt.
     * @return ChargeAttempt The mapped domain charge attempt.
     */
    protected function mapToChargeAttempt(SdkChargeAttempt $sdkChargeAttempt): ChargeAttempt
    {
        return new ChargeAttempt(
            (int)$sdkChargeAttempt->getId(),
            (string)$sdkChargeAttempt->getState(),
            $this->mapToLabels($sdkChargeAttempt->getLabels()),
        );
    }
}
