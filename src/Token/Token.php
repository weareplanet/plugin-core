<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Token;

use WeArePlanet\PluginCore\Render\JsonStringableTrait;

/**
 * Domain entity representing a customer payment Token.
 */
class Token
{
    use JsonStringableTrait;

    /**
     * @var string|null A customer-facing identifier for this token (e.g. masked card).
     */
    public ?string $customerIdentifier = null;

    /**
     * @var int The token ID.
     */
    public int $id;

    /**
     * @var int The space ID.
     */
    public ?int $spaceId = null;

    /**
     * @var State The strict state enum.
     */
    public State $state;

    /**
     * @var int The version number.
     */
    public ?int $version = null;
}
