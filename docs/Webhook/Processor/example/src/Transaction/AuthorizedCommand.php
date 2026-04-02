<?php

declare(strict_types=1);

namespace MyPlugin\ExampleWebhookImplementation\Transaction;

use WeArePlanet\PluginCore\Webhook\Command\WebhookCommand;

class AuthorizedCommand extends WebhookCommand
{
    public function execute(): mixed
    {
        $entityId = $this->context->entityId;
        
        // [DEBUG] Technical detail: Loading data
        $this->logger->debug("AuthorizedCommand: Loading fresh order data for Transaction {$entityId}...");

        // [DEBUG] Technical detail: Logic check
        $this->logger->debug("AuthorizedCommand: Checking if order is in a protected state...");

        // --- SAFE UPDATE PATTERN (Simulated) ---
        // In a real app, we would check $freshOrder->getState() here.
        // For this example, we assume it's safe.
        $isProtected = false; 

        if ($isProtected) {
            // [DEBUG] Skipped action (No change happened)
            $this->logger->debug("AuthorizedCommand: Order is already in protected state. Skipping update.");
            return null;
        }
        // ---------------------------------------

        // [INFO] Business Event: Something actually changed!
        $this->logger->info("AuthorizedCommand: Order payment authorized. Changing status to PROCESSING.");
        
        return true;
    }
}
