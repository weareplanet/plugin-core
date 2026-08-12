<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Charge\Attempt;

use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * Domain entity representing a processed charge attempt of a transaction.
 *
 * A transaction can be charged more than once (a retry after a failure, for
 * example); each of those runs is a charge attempt. The attempt carries the
 * processor-reported {@see $labels} describing how the payment was handled,
 * which is what most consumers are after — the acquirer reference or the
 * authorisation code shown on an order page, for instance.
 *
 * Instances are immutable and are produced by
 * {@see \WeArePlanet\PluginCore\Charge\ChargeGatewayInterface};
 * consumers do not construct them from API payloads themselves.
 */
readonly class ChargeAttempt
{
    use JsonStringableTrait;

    /**
     * The state a charge attempt reports once it has succeeded.
     *
     * Both API versions report the same literal, so the domain owns this rule rather
     * than each SDK adapter restating it. Compare through {@see isSuccessful()}.
     */
    public const STATE_SUCCESSFUL = 'SUCCESSFUL';

    /**
     * @param int $id The ID of the charge attempt.
     * @param string $state The state of the charge attempt as reported by the API
     *        (e.g. 'SUCCESSFUL', 'FAILED'). Kept as a string rather than an enum so an
     *        unrecognised state added by the API never breaks mapping. Prefer
     *        {@see isSuccessful()} over comparing this yourself.
     * @param list<Label> $labels The labels reported for this attempt, in the order the
     *        API returned them. Prefer {@see getLabel()} and {@see getLabelsByGroup()}
     *        over reading this array directly.
     */
    public function __construct(
        public int $id,
        public string $state,
        public array $labels = [],
    ) {
    }

    /**
     * Whether this attempt is the one that succeeded.
     *
     * A transaction can be charged more than once, but at most one of those runs
     * succeeds; this is what tells that one apart from the failed and still-processing
     * ones.
     *
     * @return bool True when the attempt succeeded.
     */
    public function isSuccessful(): bool
    {
        return $this->state === self::STATE_SUCCESSFUL;
    }

    /**
     * Returns the label for the given descriptor ID.
     *
     * @param int $descriptorId The ID of the label descriptor to look up.
     * @return Label|null The matching label, or null when this attempt carries no label
     *         for that descriptor. If the API reports several labels for the same
     *         descriptor, the first one is returned.
     */
    public function getLabel(int $descriptorId): ?Label
    {
        foreach ($this->labels as $label) {
            if ($label->descriptorId === $descriptorId) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Returns every label belonging to the given descriptor group.
     *
     * @param string $groupId The descriptor group ID to filter by, as used by
     *        {@see Label::$groupId}.
     * @return list<Label> The matching labels, in the order the API returned them.
     *         Empty when this attempt carries no label of that group.
     */
    public function getLabelsByGroup(string $groupId): array
    {
        return array_values(array_filter(
            $this->labels,
            static fn (Label $label): bool => $label->groupId === $groupId,
        ));
    }
}
