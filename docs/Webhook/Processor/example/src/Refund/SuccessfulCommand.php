<?php

declare(strict_types=1);

namespace MyPlugin\ExampleWebhookImplementation\Refund;

use WeArePlanet\PluginCore\Webhook\Command\WebhookCommand;

class SuccessfulCommand extends WebhookCommand
{
    public function execute(): mixed
    {
        $this->logger->debug("Refund/SuccessfulCommand: Loading Refund entity from SDK...");
        
        // [DEBUG] Detailed calculation info (Too noisy for production)
        $this->logger->debug("Refund/SuccessfulCommand: Calculating line item reductions for refund...");
        $this->logger->debug("Refund/SuccessfulCommand: Checking stock levels for return...");

        // [INFO] The result
        $this->logger->info("Refund/SuccessfulCommand: Creating Credit Memo in shop system.");
        
        // Return data to allow LifecycleHandler to clean up the job
        return ['refund_id' => $this->context->entityId]; 
    }
}
