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
    /**
     * Maps an SDK ChargeAttempt to a domain ChargeAttempt.
     *
     * This API reports a label descriptor's group as a bare ID, so
     * {@see Label::$groupName} is always null here. Labels whose descriptor is
     * missing from the payload are skipped: without a descriptor ID they cannot be
     * looked up by consumers.
     *
     * @param SdkChargeAttempt $sdkChargeAttempt The SDK charge attempt.
     * @return ChargeAttempt The mapped domain charge attempt.
     */
    protected function mapToChargeAttempt(SdkChargeAttempt $sdkChargeAttempt): ChargeAttempt
    {
        $labels = [];

        foreach ($sdkChargeAttempt->getLabels() ?? [] as $sdkLabel) {
            $descriptor = $sdkLabel->getDescriptor();

            if ($descriptor === null || $descriptor->getId() === null) {
                continue;
            }

            $groupId = $descriptor->getGroup();

            $labels[] = new Label(
                (int)$descriptor->getId(),
                (string)$sdkLabel->getContentAsString(),
                $groupId !== null ? (string)$groupId : null,
                // This API returns the group as a bare ID, so no name is available here.
                null,
            );
        }

        return new ChargeAttempt(
            (int)$sdkChargeAttempt->getId(),
            (string)$sdkChargeAttempt->getState(),
            $labels,
        );
    }
}
