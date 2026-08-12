<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\GlobalData\PaymentConnector;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * Value Object representing one payment connector the WeArePlanet Portal defines.
 *
 * This is global reference data — the same connectors are available to every
 * space, so there is nothing space-specific to read here. A connector is the
 * integration with a specific processor (e.g. a card acquirer, a wallet
 * provider); it is what a transaction's payment method configuration is
 * ultimately routed through — see
 * {@see \WeArePlanet\PluginCore\Transaction\TransactionPaymentMethod::$connectorId}
 * and {@see \WeArePlanet\PluginCore\Token\TokenVersion::$connectorId}.
 *
 * Instances are immutable snapshots of what the API reported at read time.
 *
 * ## Why the payment method, processor and features are IDs
 *
 * {@see $paymentMethodId}, {@see $processorId} and {@see $supportedFeatureIds}
 * hold identifiers rather than embedded entities. The APIs disagree on this —
 * one reports bare IDs, the other embeds the whole entities — and the
 * identifier is the part both always provide. Normalizing upward would mean
 * fetching the missing entities separately on one API version, so the same
 * read would cost extra round trips and gain extra failure modes there but not
 * on the other.
 */
readonly class PaymentConnector
{
    use JsonStringableTrait;

    /**
     * @param int $id The ID of the payment connector.
     * @param LocalizedString $name The localized name of the connector.
     * @param int|null $paymentMethodId The ID of the payment method this connector
     *        processes, or null when the API reported none.
     * @param int|null $processorId The ID of the processor behind this connector, or
     *        null when the API reported none.
     * @param string|null $primaryRiskTaker Which party bears the payment risk, as
     *        reported by the API (e.g. 'MERCHANT', 'CUSTOMER', 'THIRD_PARTY'), or null
     *        when the API reported none. Kept as a string rather than an enum so an
     *        unrecognised value added by the API never breaks mapping.
     * @param list<string> $supportedCurrencies The ISO 4217 currency codes this
     *        connector can process.
     * @param list<string> $supportedCustomersPresences The customer-presence values
     *        this connector supports, as reported by the API (e.g. 'VIRTUAL_PRESENT').
     * @param list<int> $supportedFeatureIds The IDs of the features this connector
     *        supports.
     * @param bool $deprecated Whether this connector is deprecated and should not be
     *        used for new configurations.
     * @param LocalizedString|null $deprecationReason The localized explanation for the
     *        deprecation, or null when the connector is not deprecated or the API
     *        reported no reason.
     */
    public function __construct(
        public int $id,
        public LocalizedString $name,
        public ?int $paymentMethodId = null,
        public ?int $processorId = null,
        public ?string $primaryRiskTaker = null,
        public array $supportedCurrencies = [],
        public array $supportedCustomersPresences = [],
        public array $supportedFeatureIds = [],
        public bool $deprecated = false,
        public ?LocalizedString $deprecationReason = null,
    ) {
    }
}
