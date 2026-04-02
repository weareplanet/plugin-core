<?php

declare(strict_types=1);

namespace MyPlugin\ExampleWebhookImplementation;

use WeArePlanet\PluginCore\State\StateMapperInterface;
use WeArePlanet\PluginCore\Transaction\State;
use \BackedEnum;

/**
 * A State Mapper to translate between plugin-core states and the "shop" states.
 */
class MyExampleStateMapper implements StateMapperInterface
{
    private const SHOP_STATE_MAP = [
        'awaiting-payment' => State::PENDING,
        'processing'       => State::CONFIRMED,
        'shipped'          => State::FULFILL,
        'cancelled'        => State::VOIDED,
    ];

    public function getLocalState(BackedEnum $pluginCoreState): string
    {
        return match ($pluginCoreState) {
            State::PENDING   => 'awaiting-payment',
            State::CONFIRMED => 'processing',
            State::FULFILL   => 'shipped',
            State::VOIDED    => 'cancelled',
            default => $pluginCoreState->value,
        };
    }

    public function getPluginCoreState(string $localState, string $enumClass): BackedEnum
    {
        $enumCase = self::SHOP_STATE_MAP[$localState] ?? null;
        if ($enumCase === null) {
            return $enumClass::from(strtoupper($localState));
        }
        return $enumCase;
    }
}
