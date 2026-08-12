<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Completion;

use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * The standardized input required to capture an authorized transaction,
 * fully or partially.
 *
 * This DTO is platform-agnostic: it carries no knowledge of any specific
 * shop system, and no SDK types.
 */
final class CaptureRequest
{
    use JsonStringableTrait;

    /**
     * @param LineItemCollection $lineItems The line items to capture, with
     *        the quantity and amount actually being captured for each (which
     *        may be less than the item's original quantity/amount for a
     *        partial capture). Pass an empty collection to capture the
     *        transaction's full remaining authorized amount.
     * @param bool $isFinal Whether this is the final capture for the
     *        transaction. Once a final capture is issued, no further
     *        captures can be made against the transaction. Defaults to true.
     *        Passing false signals that more may follow, but does not by
     *        itself guarantee a further capture will be accepted: a capture
     *        moves the transaction out of AUTHORIZED, and the API rejects a
     *        capture against a transaction that is no longer authorized.
     * @param string|null $externalId The idempotency key for this capture,
     *        1-100 characters. **Required for a partial capture** (i.e.
     *        whenever $lineItems is non-empty); optional and unused for a
     *        full capture. Reuse the same value when retrying the same
     *        capture: the API treats a repeat of a known external ID as the
     *        original capture rather than creating a second one. Derive it
     *        from something stable in your shop — a shipment ID, say — never
     *        from a fresh random value per attempt, or a retry after a
     *        timeout will capture the funds twice.
     * @param string|null $merchantReference Optional merchant-facing
     *        reference to associate with the resulting invoice.
     * @throws \InvalidArgumentException If a partial capture is described
     *         without a valid $externalId.
     */
    public function __construct(
        public readonly LineItemCollection $lineItems = new LineItemCollection(),
        public readonly bool $isFinal = true,
        public readonly ?string $externalId = null,
        public readonly ?string $merchantReference = null,
    ) {
        // API constraint: a partial capture is identified by its external ID, so
        // the API rejects the request without one. Caught here rather than at the
        // gateway so an unusable request cannot be constructed in the first place.
        // A full capture takes a different endpoint that carries no external ID.
        if ($lineItems->isEmpty()) {
            return;
        }

        if ($externalId === null || $externalId === '') {
            throw new \InvalidArgumentException(
                'A partial capture requires an externalId: it is the idempotency key the API '
                . 'uses to recognise a retried capture instead of creating a second one. '
                . 'Derive it from a stable shop-side value, such as a shipment ID.',
            );
        }

        if (mb_strlen($externalId) > 100) {
            throw new \InvalidArgumentException(
                'externalId must be at most 100 characters. Got ' . mb_strlen($externalId) . '.',
            );
        }
    }
}
