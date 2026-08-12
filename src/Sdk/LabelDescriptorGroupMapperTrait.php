<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\GlobalData\LabelDescriptorGroup\LabelDescriptorGroup;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\Sdk\Model\LabelDescriptorGroup as SdkLabelDescriptorGroup;

/**
 * Shared mapping trait for SDK LabelDescriptorGroup objects to domain objects.
 *
 * Every field is either a plain scalar or a localized map on both API
 * versions, so this mapping is identical everywhere — there is no
 * payload-shape difference to absorb.
 */
trait LabelDescriptorGroupMapperTrait
{
    /**
     * Maps an SDK LabelDescriptorGroup to a domain LabelDescriptorGroup.
     *
     * @param SdkLabelDescriptorGroup $sdkGroup The SDK label descriptor group.
     * @return LabelDescriptorGroup The mapped domain label descriptor group.
     */
    protected function mapToLabelDescriptorGroup(SdkLabelDescriptorGroup $sdkGroup): LabelDescriptorGroup
    {
        return new LabelDescriptorGroup(
            id: (int)$sdkGroup->getId(),
            name: new LocalizedString($sdkGroup->getName()),
            weight: (int)$sdkGroup->getWeight(),
        );
    }
}
