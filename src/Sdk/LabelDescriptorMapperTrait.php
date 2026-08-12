<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\GlobalData\LabelDescriptor\LabelDescriptor;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\Sdk\Model\LabelDescriptor as SdkLabelDescriptor;

/**
 * Shared mapping trait for SDK LabelDescriptor objects to domain objects.
 *
 * This API reports the owning group as a bare ID; the domain keeps it as an ID
 * so a read costs the same on every API version.
 */
trait LabelDescriptorMapperTrait
{
    /**
     * Maps an SDK LabelDescriptor to a domain LabelDescriptor.
     *
     * @param SdkLabelDescriptor $sdkDescriptor The SDK label descriptor.
     * @return LabelDescriptor The mapped domain label descriptor.
     */
    protected function mapToLabelDescriptor(SdkLabelDescriptor $sdkDescriptor): LabelDescriptor
    {
        $groupId = $sdkDescriptor->getGroup();
        $type = $sdkDescriptor->getType();

        // The SDK documents this as a pseudo-enum class, but the value it actually
        // returns is that class's bare constant string (e.g.
        // LabelDescriptorCategory::HUMAN === 'HUMAN'). is_string() reads that reality
        // rather than the inaccurate docblock.
        $category = $sdkDescriptor->getCategory();
        $category = is_string($category) ? $category : null;

        return new LabelDescriptor(
            id: (int)$sdkDescriptor->getId(),
            name: new LocalizedString($sdkDescriptor->getName()),
            groupId: $groupId !== null ? (int)$groupId : null,
            weight: (int)$sdkDescriptor->getWeight(),
            category: $category,
            type: $type !== null ? (int)$type : null,
        );
    }
}
