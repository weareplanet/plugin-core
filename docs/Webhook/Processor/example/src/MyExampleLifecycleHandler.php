<?php

declare(strict_types=1);

namespace MyPlugin\ExampleWebhookImplementation;

use WeArePlanet\PluginCore\Transaction\State as TransactionState;
use WeArePlanet\PluginCore\Webhook\DefaultWebhookLifecycleHandler;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;
use WeArePlanet\PluginCore\Webhook\WebhookContext;

class MyExampleLifecycleHandler extends DefaultWebhookLifecycleHandler
{
    private array $database = [ 456 => 'awaiting-payment' ];
    private ?MyExampleStateMapper $mapper;

    public function __construct(?MyExampleStateMapper $mapper = null)
    {
        $this->mapper = $mapper;
    }

    public function getLastProcessedState(WebhookListener $listener, int $entityId): string
    {
        $shopState = $this->database[$entityId] ?? null;
        if ($shopState === null) {
            echo "LifecycleHandler: No local state found. Returning default initial state.\n";
            // Use the helper from the base class to find the correct initial state
            // (e.g. 'CREATE' for Transaction, 'CREATE' for Refund)
            return $this->findDefaultInitialState($listener);
        }

        // Only map if it's a Transaction, as our Mapper is currently Transaction-specific
        if ($listener === WebhookListener::TRANSACTION && $this->mapper) {
            echo "LifecycleHandler: Found shop state '{$shopState}'. Translating...\n";
            return $this->mapper->getPluginCoreState($shopState, TransactionState::class)->value;
        }
        
        return $shopState;
    }

    public function getLockableResources(WebhookListener $listener, WebhookContext $context): array
    {
        return ["lock_{$listener->getTechnicalName()}_{$context->entityId}"];
    }

    protected function doAcquireLock(string $resourceId): void
    {
        echo "LifecycleHandler: Acquiring lock for '{$resourceId}'...\n";
    }

    protected function doReleaseLock(string $resourceId): void
    {
        echo "LifecycleHandler: Releasing lock for '{$resourceId}'...\n";
    }

    public function postProcess(WebhookListener $listener, WebhookContext $context, mixed $commandResult): void
    {
        $shopStateToPersist = $context->remoteState;

        // Only use the mapper for Transactions in this example
        if ($listener === WebhookListener::TRANSACTION && $this->mapper) {
            $pluginCoreEnumCase = TransactionState::from($context->remoteState);
            $shopStateToPersist = $this->mapper->getLocalState($pluginCoreEnumCase);
        }

        echo "LifecycleHandler: postProcess hook executed.\n";
        echo "LifecycleHandler: Updating local state for entity {$context->entityId} to '{$shopStateToPersist}'.\n";
        $this->database[$context->entityId] = $shopStateToPersist;

        parent::postProcess($listener, $context, $commandResult);
    }
    
    public function onFailure(WebhookListener $listener, WebhookContext $context, \Throwable $exception): void
    {
        echo "LifecycleHandler: onFailure hook executed. Error: " . $exception->getMessage() . "\n";
        parent::onFailure($listener, $context, $exception);
    }
}
