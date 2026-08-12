<?php
declare(strict_types=1);
namespace MyPlugin\ExampleWebhookImplementation\Transaction;
use WeArePlanet\PluginCore\Webhook\Command\WebhookCommand;

// 📖 Concept documentation: See docs/4-Background-Tasks/Webhook-Processor.md

class GenericCommand extends WebhookCommand {
    public function execute(): mixed {
        $this->logger->debug("GenericCommand: Processing state {$this->context->remoteState}...");
        return true;
    }
}
