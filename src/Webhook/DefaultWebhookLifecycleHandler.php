<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;
use WeArePlanet\PluginCore\State\ValidatesStateTransitions;

/**
 * Provides default implementations for the lifecycle hooks.
 * Plugins can extend this and only override the methods they need.
 */
abstract class DefaultWebhookLifecycleHandler implements WebhookLifecycleHandler
{
    /**
     * This is a mandatory method plugins must implement for state retrieval.
     */
    abstract public function getLastProcessedState(WebhookListener $listener, int $entityId): string;

    /**
     * Returns a list of unique resource identifiers to lock.
     * Defaults to an empty array (no locking) for simple webhooks.
     *
     * @return string[]
     */
    public function getLockableResources(WebhookListener $listener, WebhookContext $context): array
    {
        return [];
    }

    /**
     * Method for the platform to implement the actual locking mechanics.
     * Defaults to doing nothing. Override this if the platform supports locking.
     */
    protected function doAcquireLock(string $resourceId): void
    {
        // Do nothing by default
    }

    /**
     * Method for the platform to implement the actual unlocking mechanics.
     * Defaults to doing nothing.
     */
    protected function doReleaseLock(string $resourceId): void
    {
        // Do nothing by default
    }

    /**
     * Helper method to find the default initial state for a given listener.
     */
    final protected function findDefaultInitialState(WebhookListener $listener): string
    {
        $enumClass = $listener->getStateEnumClass();

        if ($enumClass !== null && method_exists($enumClass, 'getTransitionMap')) {
            $map = $enumClass::getTransitionMap();
            $initialStates = $map['initial'] ?? [];

            if (!empty($initialStates)) {
                return $initialStates[0];
            }
        }

        return 'CREATE';
    }

    /**
     * @inheritDoc
     */
    public function preProcess(WebhookListener $listener, WebhookContext $context): bool
    {
        // Apply auto-locking to the defined resources.
        $resources = $this->getLockableResources($listener, $context);
        foreach ($resources as $resource) {
            $this->doAcquireLock($resource);
        }

        // Proceed with the webhook processing.
        return true;
    }

    /**
     * @inheritDoc
     */
    public function postProcess(WebhookListener $listener, WebhookContext $context, mixed $commandResult): void
    {
        // Auto-Release Logic (in reverse order)
        $resources = $this->getLockableResources($listener, $context);
        foreach (array_reverse($resources) as $resource) {
            $this->doReleaseLock($resource);
        }
    }

    /**
     * @inheritDoc
     */
    public function onFailure(WebhookListener $listener, WebhookContext $context, \Throwable $exception): void
    {
        // Auto-Release Logic
        $resources = $this->getLockableResources($listener, $context);
        foreach (array_reverse($resources) as $resource) {
            $this->doReleaseLock($resource);
        }
    }
}
