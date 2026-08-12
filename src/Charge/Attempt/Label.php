<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Charge\Attempt;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * Value Object representing a single descriptor label of a charge attempt.
 *
 * Labels are the key/value details the payment processor reports back for an
 * attempt (e.g. the card brand, the acquirer reference, the authorisation
 * code). Each label is described by a label descriptor, identified by
 * {@see $descriptorId}, and descriptors may be organised into groups.
 *
 * Instances are immutable: build a new Label rather than mutating an existing
 * one.
 */
readonly class Label
{
    use JsonStringableTrait;

    /**
     * @param int $descriptorId The ID of the label descriptor this label is an instance of.
     *        Stable across API versions, so it is the reliable key for looking a label up.
     * @param string $content The label's content, formatted as a string by the API.
     * @param string|null $groupId The ID of the descriptor group this label belongs to,
     *        as a string, or null when the label's descriptor is not grouped. Use this
     *        (not {@see $groupName}) to filter labels — it is populated identically on
     *        every API version.
     * @param LocalizedString|null $groupName The localized name of the descriptor group,
     *        for display purposes only. Populated only where the API returns the group
     *        inline; null otherwise. See
     *        {@see \WeArePlanet\PluginCore\Charge\ChargeGatewayInterface}
     *        for the per-API-version detail.
     */
    public function __construct(
        public int $descriptorId,
        public string $content,
        public ?string $groupId = null,
        public ?LocalizedString $groupName = null,
    ) {
    }
}
