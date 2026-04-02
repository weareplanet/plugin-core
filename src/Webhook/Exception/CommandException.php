<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook\Exception;

/**
 * Base exception for errors that occur during a webhook command execution.
 */
class CommandException extends \Exception
{
    public function __construct(string $message = "", ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
