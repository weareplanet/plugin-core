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
     * @var float
     */
    public float $amount;

    /**
     * @var string
     */
    public string $externalId;

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
