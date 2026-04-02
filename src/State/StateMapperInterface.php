<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\State;

/**
 * Defines the contract for a class that translates between plugin-core's
 * standard state enums and a specific local system's state strings.
 */
interface StateMapperInterface
{
    /**
     * Translates a plugin-core state enum into a local-specific state string.
     *
     * @param \BackedEnum $pluginCoreState The enum case (e.g., Transaction\State::COMPLETED).
     * @return string The corresponding local state (e.g., 'wc-processing').
     */
    public function getLocalState(\BackedEnum $pluginCoreState): string;

    /**
     * Translates a local-specific state string into a plugin-core state enum.
     *
     * @param string $localState The local's state string (e.g., 'wc-processing').
     * @param class-string<\BackedEnum> $enumClass The target enum class (e.g., Transaction\State::class).
     * @return \BackedEnum The corresponding enum case (e.g., Transaction\State::COMPLETED).
     */
    public function getPluginCoreState(string $localState, string $enumClass): \BackedEnum;
}
