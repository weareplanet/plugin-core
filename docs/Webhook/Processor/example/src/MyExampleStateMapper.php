<?php

declare(strict_types=1);

namespace MyPlugin\ExampleWebhookImplementation;

use WeArePlanet\PluginCore\State\StateMapperInterface;
use WeArePlanet\PluginCore\Transaction\State as StateEnum;
use \BackedEnum;

/**
 * A State Mapper to translate between plugin-core states and the "shop" states.
 */
class MyExampleStateMapper implements StateMapperInterface
{
    private const SHOP_STATE_MAP = [
        'awaiting-payment' => StateEnum::PENDING,
        'processing'       => StateEnum::CONFIRMED,
        'shipped'          => StateEnum::FULFILL,
        'cancelled'        => StateEnum::VOIDED,
    ];

    public function getLocalState(BackedEnum $pluginCoreState): string
    {
        return match ($pluginCoreState) {
            StateEnum::PENDING   => 'awaiting-payment',
            StateEnum::CONFIRMED => 'processing',
            StateEnum::FULFILL   => 'shipped',
            StateEnum::VOIDED    => 'cancelled',
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
