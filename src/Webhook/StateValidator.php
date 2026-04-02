<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\State\ValidatesStateTransitions;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener as WebhookListenerEnum;

/**
 * Validates if a webhook 'state' is valid for a given listener.
 */
class StateValidator
{
    /**
     * Calculates the sequence of states needed to transition from the last local
     * state to a new remote state.
     *
     * Webhooks may arrive out of order or be missed. This method identifies if a
     * "leap" has occurred (e.g. from PENDING straight to PAID) and returns the
     * missing intermediate states (e.g. [AUTHORIZED, PAID]) so that the processor
     * can execute each step's business logic in the correct order.
     *
     * @param WebhookListenerEnum $listener The listener type.
     * @param string|null $lastProcessedState The state currently persisted in the shop.
     * @param string $remoteState The new state reported by the portal.
     * @return string[]|null Array of states to process, empty if already at target, or null if invalid transition.
     */
    public function getTransitionPath(WebhookListenerEnum $listener, ?string $lastProcessedState, string $remoteState): ?array
    {
        // Current State Match: No transition needed.
        if ($lastProcessedState === $remoteState) {
            return [];
        }

        $enumClass = $listener->getStateEnumClass();
        // If the entity has no complex state machine, we just accept the latest state.
        if ($enumClass === null || !in_array(ValidatesStateTransitions::class, class_uses($enumClass), true)) {
            return [$remoteState];
        }

        // Initial State: Starting a new lifecycle.
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

        // Direct Transition: Standard expected flow.
        if ($localStateCase->canTransitionTo($remoteStateCase)) {
            return [$remoteState];
        }

        // Sequence/Gap Logic: Handling missed webhooks.
        // If the states are defined in a linear sequence, we find the slice between
        // our current position and the target.
        $map = $enumClass::getTransitionMap();
        $sequence = $map['sequence'] ?? [];

        if (!empty($sequence)) {
            $previousIndex = array_search($lastProcessedState, $sequence, true);
            $currentIndex = array_search($remoteState, $sequence, true);

            // Only allow "forward" jumps in the sequence.
            if ($previousIndex !== false && $currentIndex !== false && $currentIndex > $previousIndex) {
                return array_slice($sequence, $previousIndex + 1, $currentIndex - $previousIndex);
            }
        }

        // Transition is either impossible (e.g. backward) or not defined.
        return null;
    }

    /**
     * Validates if a raw string state is a recognized member of the listener's state machine.
     *
     * This protects the system from processing invalid or experimental states
     * that might be introduced in the portal before the plugin is updated.
     *
     * @param WebhookListenerEnum $listener
     * @param string $state
     * @return bool
     */
    public function isValid(WebhookListenerEnum $listener, string $state): bool
    {
        $enumClass = $listener->getStateEnumClass();

        // If the listener has no specific state enum, we cannot validate.
        if ($enumClass === null) {
            return true;
        }

        // We use backed enums (string-based) to ensure type safety when
        // comparing against external API payloads.
        if (enum_exists($enumClass) && method_exists($enumClass, 'tryFrom')) {
            return $enumClass::tryFrom($state) !== null;
        }

        return false;
    }
}
