<?php

declare(strict_types=1);

namespace MyPlugin\ExampleWebhookImplementation\Transaction;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Webhook\Command\WebhookCommandInterface;
use WeArePlanet\PluginCore\Webhook\Listener\WebhookListenerInterface;
use WeArePlanet\PluginCore\Webhook\WebhookContext;

class TransactionStateChangeListener implements WebhookListenerInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {}

    public function getCommand(WebhookContext $context): WebhookCommandInterface
    {
        return new UpdateTransactionStateCommand($context, $this->logger);
    }
}
