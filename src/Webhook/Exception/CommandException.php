<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook\Exception;

use WeArePlanet\PluginCore\SharedKernel\AbstractDomainException;

/**
 * Base exception for errors that occur during a webhook command execution.
 */
class CommandException extends AbstractDomainException
{
}
