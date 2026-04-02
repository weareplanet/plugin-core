<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Http\Request;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;
use WeArePlanet\PluginCore\Webhook\Exception\CommandException;
use WeArePlanet\PluginCore\Webhook\Exception\SkippedStepException;
use WeArePlanet\PluginCore\Webhook\Listener\WebhookListenerRegistry;

/**
 * Processes incoming webhooks for entity state transitions.
 *
 * This processor handles the complexity of out-of-order webhooks by calculating a valid
 * transition path between the last processed state and the current remote state.
 * It ensures idempotency and correct execution of side effects (listeners).
 */
class WebhookProcessor
{
    /**
     * @param WebhookListenerRegistry $listenerRegistry Maps entity/state pairs to specific handlers.
     * @param StateValidator $stateValidator Validates if a transition is allowed vs. stale or duplicate.
     * @param WebhookLifecycleHandler $lifecycleHandler Manages persistence, locking, and failure recovery.
     * @param StateFetcherInterface $stateFetcher Retrieves the current source-of-truth state from the request.
     * @param LoggerInterface $logger The system logger.
     */
    public function __construct(
        private readonly WebhookListenerRegistry $listenerRegistry,
        private readonly StateValidator $stateValidator,
        private readonly WebhookLifecycleHandler $lifecycleHandler,
        private readonly StateFetcherInterface $stateFetcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Orchestrates the webhook processing lifecycle.
     *
     * 1. Extracts context and fetches current state.
     * 2. Validates the transition path (handles stales/duplicates).
     * 3. Executes required side effects for each step in the path.
     * 4. Handles failures with automatic rollback and retry signaling.
     *
     * @param Request $request The incoming webhook request.
     * @throws CommandException If processing fails in a way that warrants a retry.
     */
    public function process(Request $request): void
    {
        $context = null;
        $webhookListener = null;

        try {
            // Context Extraction
            $technicalName = $request->get('listenerEntityTechnicalName');
            $entityId = (int)$request->get('entityId');
            $spaceId = (int)$request->get('spaceId');

            if (!$technicalName || !$entityId || !$spaceId) {
                // We strictly require these fields to identify which business logic to apply.
                // Missing fields indicate a bad payload that cannot be recovered.
                throw new CommandException('Request body is missing required fields (technicalName, entityId, or spaceId).');
            }

            $remoteState = $this->stateFetcher->fetchState($request, $entityId);
            $webhookListener = WebhookListener::fromTechnicalName($technicalName);
            $lastProcessedState = $this->lifecycleHandler->getLastProcessedState($webhookListener, $entityId);

            // Path Calculation
            // We calculate a path to support skipped states (e.g. going from PENDING to FULFILLED directly).
            $transitionPath = $this->stateValidator->getTransitionPath($webhookListener, $lastProcessedState, $remoteState);

            if ($transitionPath === null) {
                // Stale Webhook Handling
                // Occurs when a webhook arrives for a state we have already bypassed (e.g. AUTHORIZED arriving after FULFILL).
                // We ignore these to prevent reverting the entity to an older state.
                $this->logger->debug("State transition from \"$lastProcessedState\" to \"$remoteState\" is not possible or already passed. Ignoring webhook for entity $technicalName/$entityId.");
                return;
            }

            if (empty($transitionPath)) {
                // Duplicate Webhook Handling
                // Occurs when we receive a notification for a state we just finished processing.
                $this->logger->debug("Webhook for entity $technicalName/$entityId already processed. Ignoring duplicate.");
                return;
            }

            // State Transition Execution
            $pathStr = implode(' -> ', $transitionPath);
            $this->logger->info("Processing transition path for entity $technicalName/$entityId from $lastProcessedState to $remoteState: [$pathStr]");

            $currentStateInLoop = $lastProcessedState;

            foreach ($transitionPath as $stateToProcess) {
                $context = new WebhookContext($stateToProcess, $currentStateInLoop, $entityId, $spaceId);

                // Concurrency Protection
                // We use preProcess to acquire a lock and check if this specific step was already handled by a parallel process.
                $shouldProceed = $this->lifecycleHandler->preProcess($webhookListener, $context);

                if (!$shouldProceed) {
                    $this->logger->debug("Race condition: Step $technicalName/$stateToProcess already processed. Skipping.");
                    // Even if skipped, we must invoke onFailure to ensure the lifecycle handler releases any acquired resources.
                    $this->lifecycleHandler->onFailure($webhookListener, $context, new SkippedStepException('Skipped due to race condition.'));
                    $currentStateInLoop = $stateToProcess;
                    continue;
                }

                $commandResult = null;
                $listener = $this->listenerRegistry->findListener($webhookListener, $stateToProcess);

                if ($listener !== null) {
                    // Command Execution
                    // Each step in the path may trigger a specific business command (e.g. creating an invoice).
                    $this->logger->debug("Processing step: $technicalName/$stateToProcess (Listener found)");
                    $command = $listener->getCommand($context);
                    $commandResult = $command->execute();
                } else {
                    // No-op Step
                    // Not every state transition requires a side effect, but we still track it as "processed".
                    $this->logger->debug("Processing step: $technicalName/$stateToProcess (No listener registered, skipping command)");
                }

                $this->lifecycleHandler->postProcess($webhookListener, $context, $commandResult);

                $currentStateInLoop = $stateToProcess;
            }

        } catch (CommandException $e) {
            // Validation Failures or Command issues caught as CommandException.
            // These represent client-side errors (bad payload). We log them as warnings as they don't require system-level intervention.
            $this->logger->warning("Webhook validation failed: {$e->getMessage()}");
        } catch (\Throwable $e) {
            // Failure Recovery
            // We invoke the onFailure hook to rollback active transactions and release locks.
            if ($context && $webhookListener) {
                $this->lifecycleHandler->onFailure($webhookListener, $context, $e);
            }
            $this->logger->error("Webhook processing failed: {$e->getMessage()}", ['exception' => $e]);

            // Retry Strategy
            // Re-throwing as CommandException signals the Controller to return a 5xx status.
            // This prompts the Portal to retry the delivery later, which is essential for transient failures (e.g. DB locks, network errors).
            throw new CommandException('Webhook command execution failed.', previous: $e);
        }
    }

    public function getListenerRegistry(): WebhookListenerRegistry
    {
        return $this->listenerRegistry;
    }
}
