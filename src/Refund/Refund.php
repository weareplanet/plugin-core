<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund;

use WeArePlanet\PluginCore\Render\JsonStringableTrait;

/**
 * Domain entity representing a Refund.
 */
class Refund
{
    use JsonStringableTrait;

    /**
     * @var int
     */
    public int $id;

    /**
     * @var float
     */
    public float $amount;

    /**
     * @var State
     */
    public State $state;

    /**
     * @var int
     */
    public int $transactionId;

    /**
     * @var string
     */
    public string $externalId;
}
