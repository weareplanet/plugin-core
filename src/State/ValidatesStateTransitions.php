<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\State;

trait ValidatesStateTransitions
{
    /**
     * Checks if a transition from the current state to a new state is valid.
     */
    public function canTransitionTo(self $nextState): bool
    {
        $map = self::getTransitionMap();
        $finalStates = $map['final'] ?? [];
        $transitions = $map['transitions'] ?? [];
        $anyToStates = $map['any_to'] ?? [];

        // Rule 1: If the destination is a universal target, allow it.
        if (in_array($nextState->value, $anyToStates, true)) {
            return true;
        }

        // Rule 2: If the current state is final, it can only transition to itself.
        if (in_array($this->value, $finalStates, true)) {
            return $this === $nextState;
        }

        // Rule 3: Otherwise, use the standard transition map.
        $allowedTransitions = $transitions[$this->value] ?? [];
        return in_array($nextState->value, $allowedTransitions, true);
    }
    /**
     * Defines the valid state transitions.
     * The key is the 'from' state, and the value is an array of valid 'to' states.
     * Example: [State::PENDING->value => [State::CONFIRMED->value, State::FAILED->value]]
     *
     * @return array<string, mixed>
     */
    abstract public static function getTransitionMap(): array;
}
