<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Http\Request;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener as WebhookListenerEnum;
use WeArePlanet\PluginCore\Webhook\Exception\CommandException;
use WeArePlanet\PluginCore\Webhook\Exception\SkippedStepException;
use WeArePlanet\PluginCore\Webhook\Listener\WebhookListenerRegistry;

class WebhookProcessor
{
    public function __construct(
        private readonly WebhookListenerRegistry $listenerRegistry,
        private readonly StateValidator $stateValidator,
        private readonly WebhookLifecycleHandler $lifecycleHandler,
        private readonly StateFetcherInterface $stateFetcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return WebhookListenerRegistry
     */
    public function getListenerRegistry(): WebhookListenerRegistry
    {
        return $this->listenerRegistry;
    }

    /**
     * Processes an incoming webhook request from the portal.
     *
     * Webhooks in this system are state-driven. Instead of just processing the
     * "current" state sent in the payload, this processor calculates a "transition path"
     * from the last known local state to the target remote state. This ensures that
     * all intermediate business logic (e.g. creating an invoice before marked as paid)
     * is executed even if webhooks arrive out of order or are skipped.
     *
     * @param Request $request The incoming HTTP request.
     * @return void
     * @throws CommandException If a critical processing error occurs, triggering a retry.
     */
    public function process(Request $request): void
    {
        $context = null;
        $webhookListener = null;

        try {
            // Basic Payload Validation
            $technicalName = $request->get('listenerEntityTechnicalName');
            $entityId = (int)$request->get('entityId');
            $spaceId = (int)$request->get('spaceId');

            if (!$technicalName || !$entityId || !$spaceId) {
                // We throw InvalidArgumentException for malformed payloads.
                // These are caught below and logged as warnings (not errors)
                // because they usually indicate a configuration mismatch rather
                // than a system failure.
                throw new \InvalidArgumentException('Request body is missing required fields (technicalName, entityId, or spaceId).');
            }

            // State Resolution
            // We fetch the "source of truth" state from the remote API to prevent
            // acting on potentially forged or outdated webhook payloads.
            $remoteState = $this->stateFetcher->fetchState($request, $entityId);
            $webhookListener = WebhookListenerEnum::fromTechnicalName($technicalName);
            $lastProcessedState = $this->lifecycleHandler->getLastProcessedState($webhookListener, $entityId);

            // Path Calculation
            // Computes the sequence of states that must be processed to reach $remoteState safely.
            $transitionPath = $this->stateValidator->getTransitionPath($webhookListener, $lastProcessedState, $remoteState);

            if ($transitionPath === null) {
                // null means the transition is logically impossible (e.g. trying to go
                // from PAID back to PENDING). We ignore these as "stale" or "impossible".
                $this->logger->debug(
                    sprintf(
                        'State transition from "%s" to "%s" is not possible or already passed. Ignoring webhook for entity %s/%d.',
                        $lastProcessedState,
                        $remoteState,
                        $technicalName,
                        $entityId,
                    ),
                );
                return;
            }

            // Duplicate Check
            // empty array means we are already at the target state.
            if (empty($transitionPath)) {
                $this->logger->debug(sprintf('Webhook for entity %s/%d already processed. Ignoring duplicate.', $technicalName, $entityId));
                return;
            }

            // Execution Loop
            $this->logger->info(sprintf('Processing transition path for entity %s/%d from %s to %s: [%s]', $technicalName, $entityId, $lastProcessedState, $remoteState, implode(' -> ', $transitionPath)));

            $currentStateInLoop = $lastProcessedState;

            foreach ($transitionPath as $stateToProcess) {
                $context = new WebhookContext($stateToProcess, $currentStateInLoop, $entityId, $spaceId);

                // Acquire locks and start database transactions.
                $shouldProceed = $this->lifecycleHandler->preProcess($webhookListener, $context);

                if (!$shouldProceed) {
                    // This typically happens in high-concurrency environments where
                    // another thread processed the state between our fetch and our lock.
                    $this->logger->debug(sprintf('Race condition: Step %s/%s already processed. Skipping.', $technicalName, $stateToProcess));

                    // We MUST still call onFailure to release the locks acquired in preProcess()
                    // and roll back the (now empty) transaction.
                    $this->lifecycleHandler->onFailure($webhookListener, $context, new SkippedStepException('Skipped due to race condition.'));
                    $currentStateInLoop = $stateToProcess;
                    continue;
                }

                $commandResult = null;
                $listener = $this->listenerRegistry->findListener($webhookListener, $stateToProcess);

                if ($listener !== null) {
                    // Logic for this specific state transition.
                    $this->logger->debug(sprintf('Processing step: %s/%s (Listener found)', $technicalName, $stateToProcess));
                    $command = $listener->getCommand($context);
                    $commandResult = $command->execute();
                } else {
                    // Some states exist only for tracking and have no associated shop logic.
                    $this->logger->debug(sprintf('Processing step: %s/%s (No listener registered, skipping command)', $technicalName, $stateToProcess));
                }

                // Persist state change and release locks.
                $this->lifecycleHandler->postProcess($webhookListener, $context, $commandResult);

                $currentStateInLoop = $stateToProcess;
            }

        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Webhook validation failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Global Failure Hook: Ensures that even if the business logic crashes,
            // we roll back the DB and release any distributed locks to prevent deadlocks.
            if ($context && $webhookListener) {
                $this->lifecycleHandler->onFailure($webhookListener, $context, $e);
            }
            $this->logger->error('Webhook processing failed: ' . $e->getMessage(), ['exception' => $e]);

            // We re-throw as CommandException to signal the entry-point (Controller)
            // that it should return a 5xx error. This instructs the portal to
            // retry the webhook later, which is essential for transient failures (DB/Network).
            throw new CommandException('Webhook command execution failed.', previous: $e);
        }
    }
}
