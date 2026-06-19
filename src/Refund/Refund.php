<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Render\JsonStringableTrait;

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
     * @var State
     */
    public State $state;

    /**
     * @var int
     */
    public int $transactionId;
}
