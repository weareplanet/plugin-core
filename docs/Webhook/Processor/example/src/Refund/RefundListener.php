<?php
declare(strict_types=1);
namespace MyPlugin\ExampleWebhookImplementation\Refund;
use WeArePlanet\PluginCore\Webhook\Listener\WebhookListenerInterface;
use WeArePlanet\PluginCore\Webhook\Command\WebhookCommandInterface;
use WeArePlanet\PluginCore\Webhook\WebhookContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;

class RefundListener implements WebhookListenerInterface {
    public function __construct(private readonly LoggerInterface $logger) {}
    public function getCommand(WebhookContext $context): WebhookCommandInterface {
        return new SuccessfulCommand($context, $this->logger);
    }
}
