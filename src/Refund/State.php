<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund;

use WeArePlanet\PluginCore\State\ValidatesStateTransitions;

enum State: string
{
    use ValidatesStateTransitions;

    public static function getTransitionMap(): array
    {
        return [
            'initial' => [
                self::CREATE->value,
            ],
            'transitions' => [
                self::CREATE->value      => [self::SCHEDULED->value, self::PENDING->value, self::MANUAL_CHECK->value, self::FAILED->value, self::SUCCESSFUL->value],
                self::SCHEDULED->value   => [self::MANUAL_CHECK->value, self::FAILED->value, self::PENDING->value, self::SUCCESSFUL->value],
                self::PENDING->value     => [self::MANUAL_CHECK->value, self::FAILED->value, self::SUCCESSFUL->value],
                self::MANUAL_CHECK->value => [self::FAILED->value, self::SUCCESSFUL->value],
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
                self::SCHEDULED->value,
                self::PENDING->value,
                self::MANUAL_CHECK->value,
                self::SUCCESSFUL->value,
            ],
        ];
    }

    case CREATE = 'CREATE';
    case FAILED = 'FAILED';
    case MANUAL_CHECK = 'MANUAL_CHECK';
    case PENDING = 'PENDING';
    case SCHEDULED = 'SCHEDULED';
    case SUCCESSFUL = 'SUCCESSFUL';
}
