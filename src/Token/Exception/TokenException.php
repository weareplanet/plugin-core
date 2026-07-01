<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Token\Exception;

use WeArePlanet\PluginCore\SharedKernel\AbstractDomainException;

/**
 * Thrown when token creation fails at the API or transport level.
 */
class TokenException extends AbstractDomainException
{
}
