<?php

declare(strict_types=1);

namespace MyPlugin\ExampleWebhookImplementation\Transaction;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Webhook\Command\WebhookCommand;
use WeArePlanet\PluginCore\Webhook\WebhookContext;

class UpdateTransactionStateCommand extends WebhookCommand
{
    public function __construct(
        WebhookContext $context,
        LoggerInterface $logger
    ) {
        parent::__construct($context, $logger);
    }

    public function execute(): mixed
    {
        $entityId = $this->context->entityId;
        $previous = $this->context->lastProcessedState;
        $current = $this->context->remoteState;

        // --- SAFE UPDATE PATTERN EXAMPLE ---
        // In a real integration (e.g., Magento), you must prevent race conditions here.
        //
        // 1. RELOAD FRESH DATA:
        //    Do not trust the order object you loaded before the lock.
        //    Reload it from the database now that you have the lock.
        //    $freshOrder = $this->orderRepository->get($entityId);
        //
        // 2. CHECK PROTECTED STATES:
        //    Check if the order is in a state that should NOT be overwritten
        //    (e.g. 'Manual Review', 'Shipped', 'Canceled').
        //
        //    if ($freshOrder->getState() === 'PAYMENT_REVIEW') {
        //        $this->logger->info("Skipping update. Order is in protected state.");
        //        return null;
        //    }
        // -----------------------------------

        $this->logger->info("Executing command: Processing transaction {$entityId} from '{$previous}' to '{$current}'.");

        // 3. PERFORM UPDATE:
        //    $freshOrder->setState('PROCESSING');
        //    $this->orderRepository->save($freshOrder);

        return true;
    }
}
