<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook\Command;

/**
 * Defines the contract for a command that is executed
 * when a specific webhook is received.
 */
interface WebhookCommandInterface
{
    /**
     * Executes the command's logic.
     *
     * @return mixed Can return any data that postProcess might need.
     */
    public function execute(): mixed;
}
