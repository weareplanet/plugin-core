<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund\Exception;

use WeArePlanet\PluginCore\SharedKernel\AbstractDomainException;

/**
 * Thrown when a refund operation fails at the API or transport level.
 */
class RefundException extends AbstractDomainException
{
}
