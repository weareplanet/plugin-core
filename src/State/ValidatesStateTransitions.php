<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\State;

trait ValidatesStateTransitions
{
    /**
     * Defines the valid state transitions.
     *
     * @return array<string, array<int|string, string|list<string>>>
     */
    abstract public static function getTransitionMap(): array;

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
}
