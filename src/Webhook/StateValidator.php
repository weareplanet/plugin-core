<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\State\ValidatesStateTransitions;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;

/**
 * Validates and calculates state transitions for webhook entities.
 *
 * This validator uses business-specific Enums to determine if an incoming state
 * transition is logically valid, stale, or part of a sequence that requires
 * executing intermediate steps.
 */
class StateValidator
{
    /**
     * Determines if a raw string state is recognized by the listener's domain model.
     *
     * @param WebhookListener $listener The entity type (e.g. Transaction, Refund).
     * @param string $state The state string delivered by the webhook.
     * @return bool True if the state is valid for the associated business entity.
     */
    public function isValid(WebhookListener $listener, string $state): bool
    {
        $enumClass = $listener->getStateEnumClass();

        // Generic Listener Handling
        // If no specific state enum is defined, we cannot perform strict validation.
        if ($enumClass === null) {
            return true;
        }

        // Domain Enum Validation
        // We verify that the string matches one of the backed enum cases in our domain model.
        if (enum_exists($enumClass) && method_exists($enumClass, 'tryFrom')) {
            return $enumClass::tryFrom($state) !== null;
        }

        return false;
    }

    /**
     * Calculates the path of states that must be processed to reach the remote state.
     *
     * This method handles:
     * 1. Stale webhooks: Returns null if the remote state is logically older than local.
     * 2. Duplicate webhooks: Returns an empty array if states match.
     * 3. Skipped states: Returns a list of intermediate states if a sequence is defined
     *    (e.g. PENDING -> [AUTHORIZED, CAPTURED] -> FULFILLED).
     *
     * @param WebhookListener $listener The business entity context.
     * @param string|null $lastProcessedState The state currently persisted in the local system.
     * @param string $remoteState The new state reported by the gateway.
     * @return list<string>|null List of states to process, or null if the transition is invalid/stale.
     */
    public function getTransitionPath(WebhookListener $listener, ?string $lastProcessedState, string $remoteState): ?array
    {
        // Idempotency Check
        if ($lastProcessedState === $remoteState) {
            return [];
        }

        $enumClass = $listener->getStateEnumClass();
        // If the enum doesn't implement transition logic, we treat any new state as a single-step transition.
        if ($enumClass === null || !in_array(ValidatesStateTransitions::class, class_uses($enumClass), true)) {
            return [$remoteState];
        }

        // Initial State Validation
        // If we have no local record, we only accept states explicitly marked as 'initial' in the enum map.
        if ($lastProcessedState === null) {
            $map = $enumClass::getTransitionMap();
            $initialStates = $map['initial'] ?? [];
            return in_array($remoteState, $initialStates, true) ? [$remoteState] : null;
        }

        $localStateCase = $enumClass::tryFrom($lastProcessedState);
        $remoteStateCase = $enumClass::tryFrom($remoteState);
        if ($localStateCase === null || $remoteStateCase === null) {
            return null;
        }

        // Direct Transition Validation
        if ($localStateCase->canTransitionTo($remoteStateCase)) {
            return [$remoteState];
        }

        // Sequence Path Calculation
        // Some webhooks might be skipped by the sender (e.g. going directly to FULFILLED).
        // We use the 'sequence' map to find all steps between the current and the target state
        // to ensure all business logic (invoices, emails) for intermediate steps is triggered.
        $map = $enumClass::getTransitionMap();
        $sequence = $map['sequence'] ?? [];

        if (!empty($sequence)) {
            $previousIndex = array_search($lastProcessedState, $sequence, true);
            $currentIndex = array_search($remoteState, $sequence, true);

            if ($previousIndex !== false && $currentIndex !== false && $currentIndex > $previousIndex) {
                // Returns all states in the sequence after the current one, up to and including the target.
                return array_slice($sequence, $previousIndex + 1, $currentIndex - $previousIndex);
            }
        }

        // Stale or Invalid Transition
        // If we reach here, the remote state is either already passed or not reachable from the current state.
        return null;
    }
}
