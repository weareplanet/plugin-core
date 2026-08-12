<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\GlobalData\LabelDescriptor;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * Value Object representing one label descriptor the WeArePlanet Portal defines.
 *
 * This is global reference data — the same descriptors are available to every
 * space, so there is nothing space-specific to read here. A label descriptor is
 * the *definition* of a kind of label (e.g. "card brand", "acquirer reference");
 * it is what a {@see \WeArePlanet\PluginCore\Charge\Attempt\Label}'s
 * `descriptorId` refers to. Resolving a descriptor's display name — the label
 * text a customer or merchant actually sees — is what this service is for;
 * reading the *value* attached to a specific charge attempt is
 * {@see \WeArePlanet\PluginCore\Charge\Attempt\ChargeAttempt::getLabel()}'s
 * job.
 *
 * Instances are immutable snapshots of what the API reported at read time.
 *
 * ## Why the group is an ID
 *
 * {@see $groupId} holds the owning group's identifier rather than an embedded
 * {@see \WeArePlanet\PluginCore\LabelDescriptorGroup\LabelDescriptorGroup}.
 * The APIs disagree on this — one reports the group as a bare ID, the other
 * embeds the whole group entity — and the identifier is the part both always
 * provide. Normalizing upward would mean fetching the missing group separately
 * on one API version, so the same read would cost an extra round trip and gain
 * an extra failure mode there but not on the other. Consumers that need the full
 * group resolve it through
 * {@see \WeArePlanet\PluginCore\GlobalData\GlobalDataService}
 * with this ID.
 */
readonly class LabelDescriptor
{
    use JsonStringableTrait;

    /**
     * @param int $id The ID of the label descriptor. This is what
     *        {@see \WeArePlanet\PluginCore\Charge\Attempt\Label::$descriptorId}
     *        refers to.
     * @param LocalizedString $name The localized name of the label descriptor.
     * @param int|null $groupId The ID of the group this descriptor belongs to, or null
     *        when the API reported none (the descriptor is ungrouped).
     * @param int $weight The relative display order of this descriptor among others;
     *        lower values sort first.
     * @param string|null $category The descriptor's category as reported by the API
     *        (e.g. 'HUMAN', 'APPLICATION'), or null when the API reported none. Kept as
     *        a string rather than an enum so an unrecognised category added by the API
     *        never breaks mapping.
     * @param int|null $type The ID of the descriptor's type, or null when the API
     *        reported none.
     */
    public function __construct(
        public int $id,
        public LocalizedString $name,
        public ?int $groupId = null,
        public int $weight = 0,
        public ?string $category = null,
        public ?int $type = null,
    ) {
    }
}
