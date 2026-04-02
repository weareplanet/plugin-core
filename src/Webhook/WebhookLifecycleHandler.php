<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;

/**
 * Defines the contract for a class that handles all shop-specific
 * persistence (database) logic for a webhook event.
 */
interface WebhookLifecycleHandler
{
    /**
     * Fetches the last *processed* webhook state from the local persistence.
     * If no state is found, this should return an initial state (e.g., 'CREATE').
     */
    public function getLastProcessedState(WebhookListener $listener, int $entityId): string;

    /**
     * Returns a list of unique resource identifiers that must be locked.
     *
     * @param WebhookListener $listener
     * @param WebhookContext $context The full context (includes entityId and spaceId)
     * @return string[]
     */
    public function getLockableResources(WebhookListener $listener, WebhookContext $context): array;
    /**
     * Called by the WebhookProcessor *before* the command is executed.
     * This is the place to start a database transaction and acquire locks.
     * It must re-check the local state to prevent race conditions.
     *
     * @return bool Returns true to proceed, or false to skip this step.
     */
    public function preProcess(WebhookListener $listener, WebhookContext $context): bool;

    /**
     * Called by the WebhookProcessor *after* the command executes successfully.
     * This is the place to commit the transaction, update the last_processed_state,
     * and release locks.
     *
     * @param WebhookContext $context
     * @param mixed $commandResult The value returned by the command's execute() method.
     */
    public function postProcess(WebhookListener $listener, WebhookContext $context, mixed $commandResult): void;

    /**
     * Called by the WebhookProcessor if an exception or error occurs.
     * This is the place to roll back the transaction and release locks.
     */
    public function onFailure(WebhookListener $listener, WebhookContext $context, \Throwable $exception): void;
}
