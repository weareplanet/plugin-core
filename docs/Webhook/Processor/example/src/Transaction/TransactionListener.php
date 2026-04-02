<?php
declare(strict_types=1);
namespace MyPlugin\ExampleWebhookImplementation\Transaction;

use WeArePlanet\PluginCore\Webhook\Listener\WebhookListenerInterface;
use WeArePlanet\PluginCore\Webhook\Command\WebhookCommandInterface;
use WeArePlanet\PluginCore\Webhook\WebhookContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Transaction\State as StateEnum;

class TransactionListener implements WebhookListenerInterface {
    public function __construct(private readonly LoggerInterface $logger) {}

    public function getCommand(WebhookContext $context): WebhookCommandInterface {
        // Route to specific commands based on state
        return match ($context->remoteState) {
            StateEnum::AUTHORIZED->value => new AuthorizedCommand($context, $this->logger),
            StateEnum::FULFILL->value    => new FulfillCommand($context, $this->logger),
            default                  => new GenericCommand($context, $this->logger),
        };
    }
}
