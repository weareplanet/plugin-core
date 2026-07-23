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
     * @param string|null $externalId Optional idempotency/reference
     *        identifier for this specific capture request.
     * @param string|null $merchantReference Optional merchant-facing
     *        reference to associate with the resulting invoice.
     */
    public function __construct(
        public readonly LineItemCollection $lineItems = new LineItemCollection(),
        public readonly bool $isFinal = true,
        public readonly ?string $externalId = null,
        public readonly ?string $merchantReference = null,
    ) {
    }
}
