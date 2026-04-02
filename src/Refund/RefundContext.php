<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund;

use WeArePlanet\PluginCore\Render\JsonStringableTrait;

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
     * @param array $lineItems Optional list of line item reductions: [['uniqueId' => string, 'quantity' => float, 'amount' => float]].
     *                         NOTE: 'amount' is the Unit Price Reduction per remaining item, NOT the total reduction amount.
     *                         See docs/Refund/README.md for calculation formula.
     */
    public function __construct(
        public readonly int $transactionId,
        public readonly float $amount,
        public readonly string $merchantReference,
        public readonly Type $type,
        /** @var list<array{uniqueId: ?string, quantity: float, amount: float}> List of line item reductions. */
        public readonly array $lineItems = [],
        public ?string $externalId = null,
    ) {
    }
}
