<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Token;

use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * Domain entity representing a customer payment token.
 *
 * A token is the WeArePlanet Portal's reference to a customer's stored payment credentials.
 * It is the long-lived handle a plugin keeps against a customer; the credentials
 * themselves live in the WeArePlanet Portal and are never exposed here.
 *
 * A token owns one or more {@see TokenVersion} snapshots, at most one of which is
 * active at a time. The token is the stable identity; the version is what was
 * actually in force at a given point.
 *
 * Instances are immutable: a gateway returns a fresh entity for every read, so a
 * token held in a variable never changes underneath the caller.
 */
readonly class Token
{
    use JsonStringableTrait;

    /**
     * @param int $id The ID of the token.
     * @param State $state The lifecycle state of the token.
     * @param int|null $spaceId The ID of the space the token belongs to, or null when
     *        the API reported none.
     * @param int|null $version The optimistic-locking revision of the token record.
     *        This is a concurrency counter, not a {@see TokenVersion} — the two are
     *        unrelated despite the similar name.
     * @param string|null $customerId The shop's own identifier for the customer this
     *        token belongs to, as supplied when the token was created and echoed back
     *        by the WeArePlanet Portal. This is the key to filter by when listing one customer's
     *        saved payment methods; null when the token was created without one.
     * @param string|null $customerIdentifier A customer-facing identifier for this
     *        token, such as an email address or a masked card number. Suitable for
     *        display, not as a key — use $customerId for that.
     * @param \DateTimeImmutable|null $createdOn When the token was created, or null
     *        when the API reported no timestamp.
     */
    public function __construct(
        public int $id,
        public State $state,
        public ?int $spaceId = null,
        public ?int $version = null,
        public ?string $customerId = null,
        public ?string $customerIdentifier = null,
        public ?\DateTimeImmutable $createdOn = null,
    ) {
    }

    /**
     * Whether this token can currently be charged.
     *
     * Only an active token is usable for a new charge: one that is still being
     * created, has been deactivated, or is on its way out cannot be.
     *
     * @return bool True when the token is active.
     */
    public function isChargeable(): bool
    {
        return $this->state === State::ACTIVE;
    }
}
