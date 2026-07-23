<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook\Command;

use WeArePlanet\PluginCore\Webhook\Exception\TransientWebhookException;

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
     * @throws TransientWebhookException When a temporary, self-healing condition
     *         (e.g. a lock contention timeout) prevents processing right now.
     */
    public function execute(): mixed;
}
