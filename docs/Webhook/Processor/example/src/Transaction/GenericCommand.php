<?php
declare(strict_types=1);
namespace MyPlugin\ExampleWebhookImplementation\Transaction;
use WeArePlanet\PluginCore\Webhook\Command\WebhookCommand;

class GenericCommand extends WebhookCommand {
    public function execute(): mixed {
        $this->logger->debug("GenericCommand: Processing state {$this->context->remoteState}...");
        return true;
    }
}
