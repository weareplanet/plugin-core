<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Http\Request;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;
use WeArePlanet\PluginCore\Webhook\Exception\CommandException;
use WeArePlanet\PluginCore\Webhook\Exception\SkippedStepException;
use WeArePlanet\PluginCore\Webhook\Exception\TransientWebhookException;
use WeArePlanet\PluginCore\Webhook\Listener\WebhookListenerRegistry;

/**
 * Processes incoming webhooks for entity state transitions.
 *
 * This processor handles the complexity of out-of-order webhooks by calculating a valid
 * transition path between the last processed state and the current remote state.
 * It ensures idempotency and correct execution of side effects (listeners).
 */
#[LogContext(domain: 'webhook')]
class WebhookProcessor
{
    use DomainLoggerTrait;
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
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * Orchestrates the webhook processing lifecycle.
     *
     * - Extracts context and fetches current state.
     * - Validates the transition path (handles stales/duplicates).
     * - Executes required side effects for each step in the path.
     * - Handles failures with automatic rollback and retry signaling.
     *
     * @param Request $request The incoming webhook request.
     * @throws CommandException If processing fails in a way that warrants a retry.
     */
    public function process(Request $request): void
    {
        $context = null;
        $webhookListener = null;
        $technicalName = null;
        $entityId = null;
        $spaceId = null;

        try {
            // Context Extraction
            $technicalName = $request->get('listenerEntityTechnicalName');
            $entityId = (int)$request->get('entityId');
            $spaceId = (int)$request->get('spaceId');

            if (!$technicalName || !$entityId || !$spaceId) {
                // We strictly require these fields to identify which business logic to apply.
                // Missing fields indicate a bad payload that cannot be recovered.
                throw new CommandException(
                    "Request body is missing required fields (technicalName, entityId, or spaceId). Got technicalName: '{$technicalName}', entityId: '{$entityId}', spaceId: '{$spaceId}'.",
                    new LocalizedString('Request body is missing required fields (technicalName, entityId, or spaceId).'),
                );
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
                $this->logger->debug(
                    "State transition is not possible or already passed. Ignoring webhook.",
                    [
                        'lastProcessedState' => $lastProcessedState,
                        'remoteState' => $remoteState,
                        'technicalName' => $technicalName,
                        'entityId' => $entityId,
                    ],
                );
                return;
            }

            if (empty($transitionPath)) {
                // Duplicate Webhook Handling
                // Occurs when we receive a notification for a state we just finished processing.
                $this->logger->debug("Webhook already processed; ignoring duplicate.", [
                    'technicalName' => $technicalName,
                    'entityId' => $entityId,
                    'spaceId' => $spaceId,
                ]);
                return;
            }

            // State Transition Execution
            $this->logger->info("Processing transition path.", [
                'technicalName' => $technicalName,
                'entityId' => $entityId,
                'fromState' => $lastProcessedState,
                'toState' => $remoteState,
                'transitionPath' => $transitionPath,
                'spaceId' => $spaceId,
            ]);

            $currentStateInLoop = $lastProcessedState;

            foreach ($transitionPath as $stateToProcess) {
                $context = new WebhookContext($stateToProcess, $currentStateInLoop, $entityId, $spaceId);

                // Concurrency Protection
                // We use preProcess to acquire a lock and check if this specific step was already handled by a parallel process.
                $shouldProceed = $this->lifecycleHandler->preProcess($webhookListener, $context);

                if (!$shouldProceed) {
                    $this->logger->debug("Race condition: step already processed; skipping.", [
                        'technicalName' => $technicalName,
                        'state' => $stateToProcess,
                        'entityId' => $entityId,
                        'spaceId' => $spaceId,
                    ]);
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
                    $this->logger->debug("Processing step (listener found).", [
                        'technicalName' => $technicalName,
                        'state' => $stateToProcess,
                        'entityId' => $entityId,
                        'spaceId' => $spaceId,
                    ]);
                    $command = $listener->getCommand($context);
                    $commandResult = $command->execute();
                } else {
                    // No-op Step
                    // Not every state transition requires a side effect, but we still track it as "processed".
                    $this->logger->debug("Processing step (no listener registered, skipping command).", [
                        'technicalName' => $technicalName,
                        'state' => $stateToProcess,
                        'entityId' => $entityId,
                        'spaceId' => $spaceId,
                    ]);
                }

                $this->lifecycleHandler->postProcess($webhookListener, $context, $commandResult);

                $currentStateInLoop = $stateToProcess;
            }

        } catch (CommandException $e) {
            // Validation Failures or Command issues caught as CommandException.
            // These represent client-side errors (bad payload). We log them as warnings as they don't require system-level intervention.
            // Normalized for the same reason as the transient branch above: a rejected
            // payload is an expected outcome, not a fault to surface with a trace.
            $this->logger->warning(
                'Webhook validation failed.',
                [
                    'entityId' => $entityId,
                    'spaceId' => $spaceId,
                    'listener' => $technicalName,
                    'reason' => $e->getMessage(),
                ],
            );
        } catch (TransientWebhookException $e) {
            // Transient Failure Hook: same recovery as the generic handler, but
            // the consumer told us this is a temporary, self-healing state
            // (e.g. lock contention), so we log at info severity instead of error.
            if ($context && $webhookListener) {
                $this->lifecycleHandler->onFailure($webhookListener, $context, $e);
            }
            // The reason travels as a normalized string in context, never as a raw
            // Throwable: a caught, self-healing condition must not render like an
            // unhandled error. How a backend stringifies a Throwable is its own
            // business — including file-and-line fragments that read as a stack trace —
            // so nothing below error level relies on that.
            $this->logger->info(
                'Webhook processing delayed: transient condition (will be retried).',
                [
                    'entityId' => $entityId,
                    'spaceId' => $spaceId,
                    'listener' => $technicalName,
                    'reason' => $e->getMessage(),
                ],
            );

            // Still re-throw as CommandException so the Controller returns a
            // 5xx and the WeArePlanet Portal retries the delivery.
            throw new CommandException(
                "Webhook command execution failed for entity {$entityId} with listener {$technicalName} under space {$spaceId}.",
                new LocalizedString('Webhook command execution failed.'),
                $e,
            );
        } catch (\Throwable $e) {
            // Failure Recovery
            // We invoke the onFailure hook to rollback active transactions and release locks.
            if ($context && $webhookListener) {
                $this->lifecycleHandler->onFailure($webhookListener, $context, $e);
            }
            $this->logger->error("Webhook processing failed.", ['exception' => $e]);

            // Retry Strategy
            // Re-throwing as CommandException signals the Controller to return a 5xx status.
            // This prompts the WeArePlanet Portal to retry the delivery later, which is essential for transient failures (e.g. DB locks, network errors).
            throw new CommandException(
                "Webhook command execution failed for entity {$entityId} with listener {$technicalName} under space {$spaceId}.",
                new LocalizedString('Webhook command execution failed.'),
                $e,
            );
        }
    }

    public function getListenerRegistry(): WebhookListenerRegistry
    {
        return $this->listenerRegistry;
    }
}
