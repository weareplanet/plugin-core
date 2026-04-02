<?php

declare(strict_types=1);

namespace MyPlugin\ExampleWebhookImplementation\Transaction;

use WeArePlanet\PluginCore\Webhook\Command\WebhookCommand;

class FulfillCommand extends WebhookCommand
{
    public function execute(): mixed
    {
        // [DEBUG] Internal flow
        $this->logger->debug("FulfillCommand: Verifying transaction settlement status...");

        // [DEBUG] State logic
        $this->logger->debug("FulfillCommand: Checking if order is currently in 'Payment Review'...");

        // --- Logic Simulation ---
        // if ($order->getState() === 'PAYMENT_REVIEW') { ... }
        // ------------------------

        // [INFO] Major Business Milestone
        $this->logger->info("FulfillCommand: Payment guaranteed. Order is ready to ship.");
        
        return true;
    }
}
