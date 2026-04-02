<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\ManualTask;

use WeArePlanet\PluginCore\State\ValidatesStateTransitions;

enum State: string
{
    use ValidatesStateTransitions;

    case OPEN = 'OPEN';
    case DONE = 'DONE';
    case EXPIRED = 'EXPIRED';

    public static function getTransitionMap(): array
    {
        return [
            'initial' => [
                self::OPEN->value,
            ],
            'transitions' => [
                self::OPEN->value => [self::DONE->value, self::EXPIRED->value],
            ],
            'final' => [
                self::DONE->value,
                self::EXPIRED->value,
            ],
            'any_to' => [
                self::EXPIRED->value,
            ],
            'sequence' => [
                self::OPEN->value,
                self::DONE->value,
            ],
        ];
    }
}
