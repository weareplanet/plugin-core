<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\Charge\Attempt\Label;
use WeArePlanet\Sdk\Model\Label as SdkLabel;

/**
 * Shared mapping trait for SDK Label objects to domain Label objects.
 *
 * Several entities besides {@see \WeArePlanet\PluginCore\Charge\Attempt\ChargeAttempt}
 * carry a `labels` collection of the same shape (Refund, TokenVersion,
 * TransactionCompletion, ...), so the mapping is centralized here rather than
 * repeated per entity mapper.
 */
trait LabelMapperTrait
{
    /**
     * Maps an SDK Label array to a list of domain Labels.
     *
     * This API reports a label descriptor's group as a bare ID, so
     * {@see Label::$groupName} is always null here. Labels whose descriptor is
     * missing from the payload are skipped: without a descriptor ID they cannot be
     * looked up by consumers.
     *
     * @param SdkLabel[]|null $sdkLabels The SDK labels, or null when the payload
     *        carried none.
     * @return list<Label> The mapped domain labels.
     */
    protected function mapToLabels(?array $sdkLabels): array
    {
        $labels = [];

        foreach ($sdkLabels ?? [] as $sdkLabel) {
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

        return $labels;
    }
}
