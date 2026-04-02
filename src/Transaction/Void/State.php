<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Void;

use WeArePlanet\PluginCore\State\ValidatesStateTransitions;

enum State: string
{
    use ValidatesStateTransitions;

    case CREATE = 'CREATE';
    case PENDING = 'PENDING';
    case FAILED = 'FAILED';
    case SUCCESSFUL = 'SUCCESSFUL';

    public static function getTransitionMap(): array
    {
        return [
            'initial' => [
                self::CREATE->value,
            ],
            'transitions' => [
                self::CREATE->value  => [self::PENDING->value, self::SUCCESSFUL->value, self::FAILED->value],
                self::PENDING->value => [self::SUCCESSFUL->value, self::FAILED->value],
            ],
            'final' => [
                self::FAILED->value,
                self::SUCCESSFUL->value,
            ],
            'any_to' => [
                self::FAILED->value,
            ],
            'sequence' => [
                self::CREATE->value,
                self::PENDING->value,
                self::SUCCESSFUL->value,
            ],
        ];
    }
}
