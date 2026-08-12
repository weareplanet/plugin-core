<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\DeliveryIndication;

use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * Domain entity representing a delivery indication.
 *
 * A delivery indication is the WeArePlanet Portal's request for a merchant decision on
 * whether a captured payment is suitable for delivery. It is raised against a
 * transaction completion, and is resolved by marking it suitable or not
 * suitable — see {@see DeliveryIndicationService}.
 *
 * Instances are immutable snapshots of what the API reported at read time.
 * Marking an indication does not mutate the object you hold; the gateway
 * returns the updated entity.
 *
 * ## Why the completion is an ID
 *
 * {@see $completionId} holds the completion's identifier rather than an embedded
 * completion object. The APIs disagree on this field — one reports the completion
 * as a bare ID, the other embeds the whole completion entity — and the identifier
 * is the part both always provide. Normalizing upward (always embedding a
 * completion) would mean fetching the completion separately wherever the API does
 * not send it, making the same call cost an extra round trip and gain an extra
 * failure mode on one API but not the other. Normalizing downward keeps a read
 * cheap and behaviourally identical everywhere, and consumers that need the full
 * completion resolve it through the completion feature with this ID.
 */
readonly class DeliveryIndication
{
    use JsonStringableTrait;

    /**
     * @param int $id The ID of the delivery indication.
     * @param int $spaceId The ID of the space the delivery indication belongs to.
     * @param State $state The current state of the delivery indication. A decision is
     *        only meaningful while this is {@see State::PENDING} or
     *        {@see State::MANUAL_CHECK_REQUIRED}; the other states are final.
     * @param int|null $transactionId The ID of the transaction this indication was
     *        raised for, or null when the API reported none.
     * @param int|null $completionId The ID of the transaction completion this indication
     *        was raised against, or null when the API reported none. Deliberately an ID
     *        rather than an embedded completion object — see the class note below.
     */
    public function __construct(
        public int $id,
        public int $spaceId,
        public State $state,
        public ?int $transactionId = null,
        public ?int $completionId = null,
    ) {
    }

    /**
     * Whether this indication is still awaiting a decision.
     *
     * Marking an indication that has already been decided is rejected by the API,
     * so consumers can use this to decide whether to offer the action at all.
     *
     * @return bool True while a decision can still be made.
     */
    public function isDecisionPending(): bool
    {
        return $this->state === State::PENDING || $this->state === State::MANUAL_CHECK_REQUIRED;
    }
}
