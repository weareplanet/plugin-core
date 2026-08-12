<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * Immutable snapshot of the payment method a transaction was processed with.
 *
 * This records what was actually applied to one specific transaction at the
 * moment it was processed. It is deliberately *not* a reference to the
 * merchant's payment method configuration: that configuration is a live entity
 * the merchant can rename, re-point to another connector, re-image or disable
 * at any time, and reading it back later would describe the configuration as it
 * is *now*, not as it was when this transaction ran.
 *
 * Holding the handful of values that were in effect keeps historical records
 * (order views, receipts, reconciliation exports) truthful after the merchant
 * changes their setup. Every property is nullable because the API omits the
 * payment connector configuration until a payment method has actually been
 * selected, and may omit individual fields within it.
 *
 * To act on the *current* configuration instead — its title, description or
 * availability — resolve it through the payment method feature using
 * {@see $paymentMethodId}.
 */
readonly class TransactionPaymentMethod
{
    use JsonStringableTrait;

    /**
     * @param int|null $paymentMethodId The ID of the payment method configuration used for
     *        this transaction, as it was at processing time. Null when the API reported no
     *        payment method configuration. Use it to look the live configuration up when
     *        current values are what you need.
     * @param int|null $connectorId The ID of the payment connector that processed this
     *        transaction, as it was at processing time. Null when the API reported none.
     * @param string|null $resolvedImageUrl The absolute URL of the payment method image as
     *        resolved when this transaction was processed. Kept verbatim so a stored
     *        historical record keeps rendering the icon the customer actually saw, even
     *        after the merchant replaces it. Null when the API reported no image.
     */
    public function __construct(
        public ?int $paymentMethodId = null,
        public ?int $connectorId = null,
        public ?string $resolvedImageUrl = null,
    ) {
    }
}
