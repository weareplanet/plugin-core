<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * Immutable snapshot of the environment a transaction was processed in.
 *
 * These are the values that were actually in effect for one specific
 * transaction — the space view it ran under and the language it ran in — not a
 * reference to the merchant's current space view or shop locale settings. Those
 * are live and can be changed at any time; re-reading them later would describe
 * the shop as it is *now* and silently rewrite history.
 *
 * Keeping the values that were used means a historical record stays truthful:
 * a receipt reprinted a year later still reports the language the customer
 * checked out in. Both properties are nullable because the API omits them when
 * the transaction carries no explicit environment.
 */
readonly class TransactionEnvironment
{
    use JsonStringableTrait;

    /**
     * @param int|null $spaceViewId The ID of the space view this transaction was processed
     *        under, as it was at processing time. Space views select the branding and
     *        configuration variant applied to the payment flow. Null when the transaction
     *        ran under the space's default view, or when the API reported none.
     * @param string|null $language The language this transaction was processed in, as an
     *        IETF language tag (e.g. 'en-US'), as it was at processing time. This is the
     *        language the customer was actually served, which is what a historical record
     *        should reproduce. Null when the API reported none.
     */
    public function __construct(
        public ?int $spaceViewId = null,
        public ?string $language = null,
    ) {
    }
}
