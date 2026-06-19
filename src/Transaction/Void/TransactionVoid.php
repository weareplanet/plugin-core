<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Void;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Render\JsonStringableTrait;

/**
 * Domain object representing a Transaction Void.
 *
 * This object decouples the domain layer from the underlying SDK models and enables
 * localization of void failure reasons for the consumer.
 */
class TransactionVoid
{
    use JsonStringableTrait;

    /**
     * @var LocalizedString|null The localized failure reason provided by the gateway if the void failed.
     */
    public ?LocalizedString $failureReason = null;

    /**
     * @var State The state of the void operation.
     */
    public State $state;
}
