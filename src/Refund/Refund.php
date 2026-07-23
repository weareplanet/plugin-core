<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund;

use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * Domain entity representing a Refund.
 */
class Refund
{
    use JsonStringableTrait;

    /**
     * @var float
     */
    public float $amount;

    /**
     * @var \DateTimeImmutable|null The date/time when the refund was created.
     */
    public ?\DateTimeImmutable $createdOn = null;

    /**
     * @var string
     */
    public string $externalId;

    /**
     * @var \DateTimeImmutable|null The date/time when the refund failed.
     */
    public ?\DateTimeImmutable $failedOn = null;

    /**
     * @var LocalizedString|null The localized failure reason from the API.
     */
    public ?LocalizedString $failureReason = null;

    /**
     * @var int
     */
    public int $id;

    /**
     * @var LineItemCollection|null The line items included in the refund,
     *      representing the reductions that were applied.
     */
    public ?LineItemCollection $lineItems = null;

    /**
     * @var LineItemCollection|null The line items of the original transaction,
     *      adjusted to reflect all reductions applied so far — i.e. the
     *      post-refund cart state, which is what remains refundable.
     */
    public ?LineItemCollection $reducedLineItems = null;

    /**
     * @var State
     */
    public State $state;

    /**
     * @var int
     */
    public int $transactionId;
}
