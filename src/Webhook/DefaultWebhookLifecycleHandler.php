<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener as WebhookListenerEnum;

/**
 * Provides default implementations for the lifecycle hooks.
 * Plugins can extend this and only override the methods they need.
 */
abstract class DefaultWebhookLifecycleHandler implements WebhookLifecycleHandler
{
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
    final protected function findDefaultInitialState(WebhookListenerEnum $listener): string
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
     * This is a mandatory method plugins must implement for state retrieval.
     */
    abstract public function getLastProcessedState(WebhookListenerEnum $listener, int $entityId): string;

    /**
     * Returns a list of unique resource identifiers to lock.
     * Defaults to an empty array (no locking) for simple webhooks.
     *
     * @return string[]
     */
    public function getLockableResources(WebhookListenerEnum $listener, WebhookContext $context): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function onFailure(WebhookListenerEnum $listener, WebhookContext $context, \Throwable $exception): void
    {
        // Auto-Release Logic
        $resources = $this->getLockableResources($listener, $context);
        foreach (array_reverse($resources) as $resource) {
            $this->doReleaseLock($resource);
        }
    }

    /**
     * @inheritDoc
     */
    public function postProcess(WebhookListenerEnum $listener, WebhookContext $context, mixed $commandResult): void
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
    public function preProcess(WebhookListenerEnum $listener, WebhookContext $context): bool
    {
        // Auto-Locking Logic
        $resources = $this->getLockableResources($listener, $context);
        foreach ($resources as $resource) {
            $this->doAcquireLock($resource);
        }

        // Default: Always proceed
        return true;
    }
}
