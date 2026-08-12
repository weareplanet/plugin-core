<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\GlobalData\LabelDescriptorGroup;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * Value Object representing one group that label descriptors can belong to.
 *
 * This is global reference data — the same groups are available to every
 * space, so there is nothing space-specific to read here. A group is a
 * category of related label descriptors (see
 * {@see \WeArePlanet\PluginCore\LabelDescriptor\LabelDescriptor}), and
 * is what a {@see \WeArePlanet\PluginCore\Charge\Attempt\Label}'s
 * `groupId` refers to. Resolving a group's display name is what this service
 * is for; grouping labels themselves is
 * {@see \WeArePlanet\PluginCore\Charge\Attempt\ChargeAttempt::getLabelsByGroup()}'s
 * job.
 *
 * Instances are immutable snapshots of what the API reported at read time.
 */
readonly class LabelDescriptorGroup
{
    use JsonStringableTrait;

    /**
     * @param int $id The ID of the label descriptor group.
     * @param LocalizedString $name The localized name of the group.
     * @param int $weight The relative display order of this group among others; lower
     *        values sort first.
     */
    public function __construct(
        public int $id,
        public LocalizedString $name,
        public int $weight,
    ) {
    }
}
