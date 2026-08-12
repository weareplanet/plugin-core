<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Token;

use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * Value Object representing one version of a payment token.
 *
 * A {@see Token} is a stable identity; the credentials behind it can be replaced
 * — a customer updates an expiring card, for instance. Each set of credentials is
 * a version, and at most one version of a token is active at a time. Older
 * versions become obsolete but remain readable, which is what makes a historical
 * charge explainable after the fact.
 *
 * Instances are immutable snapshots of what the API reported at read time.
 *
 * ## Why the references are IDs
 *
 * The four reference properties hold identifiers rather than embedded configuration
 * objects. The APIs disagree on this — one reports bare IDs, the other embeds whole
 * entities — and the identifier is the part both always provide. Normalizing upward
 * would mean fetching the missing entities separately on one API version, so the
 * same read would cost an extra round trip and gain an extra failure mode there but
 * not on the other. Consumers that need the full configuration resolve it through
 * the payment method feature.
 *
 * ## Two kinds of reference: type versus configuration
 *
 * The references come in pairs, and the two kinds are not interchangeable:
 *
 * - {@see $connectorId} and {@see $paymentMethodId} identify the *global* connector
 *   and payment-method type. Every space sees the same values; they are the IDs
 *   {@see \WeArePlanet\PluginCore\GlobalData\PaymentConnector} lists.
 * - {@see $connectorConfigurationId} and {@see $paymentMethodConfigurationId}
 *   identify the *space-scoped configuration* the credentials were created under —
 *   one merchant's particular setup, such as "Visa via a specific acquirer".
 *
 * Use the configuration IDs to resolve a stored token back to a merchant's
 * configured payment method. In particular
 * {@see \WeArePlanet\PluginCore\PaymentMethod\PaymentMethod::$id} is a
 * *configuration* ID, so it matches {@see $paymentMethodConfigurationId} — not
 * {@see $paymentMethodId}, which identifies the type and would silently fail to
 * join against a plugin's synced payment-method records.
 */
readonly class TokenVersion
{
    use JsonStringableTrait;

    /**
     * @param int $id The ID of the token version.
     * @param Token $token The token this version belongs to.
     * @param Version\State $state The lifecycle state of this version. Only an
     *        {@see Version\State::ACTIVE} version is the one currently in force.
     * @param string|null $name A human-readable name for this version as assigned by
     *        the WeArePlanet Portal, or null when the API reported none.
     * @param int|null $linkedSpaceId The ID of the space this version belongs to, or
     *        null when the API reported none.
     * @param int|null $connectorId The ID of the payment connector *type* these
     *        credentials are bound to, or null when the API reported none. Global,
     *        not space-scoped — see $connectorConfigurationId for the configuration.
     * @param int|null $paymentMethodId The ID of the payment method *type* these
     *        credentials represent, or null when the API reported none. Global, not
     *        space-scoped — see $paymentMethodConfigurationId for the configuration.
     * @param int|null $connectorConfigurationId The ID of the space-scoped connector
     *        configuration these credentials were created under, or null when the API
     *        reported none.
     * @param int|null $paymentMethodConfigurationId The ID of the space-scoped payment
     *        method configuration these credentials were created under, or null when
     *        the API reported none. This is the ID that matches
     *        {@see \WeArePlanet\PluginCore\PaymentMethod\PaymentMethod::$id},
     *        so it is what a locally synced payment-method record is keyed by.
     */
    public function __construct(
        public int $id,
        public Token $token,
        public Version\State $state,
        public ?string $name = null,
        public ?int $linkedSpaceId = null,
        public ?int $connectorId = null,
        public ?int $paymentMethodId = null,
        public ?int $connectorConfigurationId = null,
        public ?int $paymentMethodConfigurationId = null,
    ) {
    }

    /**
     * Whether this is the version currently in force for its token.
     *
     * A token's credentials are only usable through its active version; an obsolete
     * version describes credentials that have since been replaced.
     *
     * @return bool True when this version is active.
     */
    public function isActive(): bool
    {
        return $this->state === Version\State::ACTIVE;
    }
}
