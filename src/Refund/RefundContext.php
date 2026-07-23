<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund;

use WeArePlanet\PluginCore\Refund\LineItem\RefundLineItemCollection;
use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * The standardized input required to create a refund.
 */
class RefundContext
{
    use JsonStringableTrait;

    /**
     * @param int $transactionId
     * @param float $amount
     * @param string $merchantReference
     * @param Type $type
     * @param RefundLineItemCollection $lineItems Optional list of line item reductions.
     *                         NOTE: {@see RefundLineItem::$unitPriceReduction} is the Unit Price Reduction per
     *                         remaining item, NOT the total reduction amount.
     *                         See docs/Refund/README.md for calculation formula.
     */
    public function __construct(
        public readonly int $transactionId,
        public readonly float $amount,
        public readonly string $merchantReference,
        public readonly Type $type,
        public readonly RefundLineItemCollection $lineItems = new RefundLineItemCollection(),
        public ?string $externalId = null,
    ) {
    }
}
