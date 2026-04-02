<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund;

use WeArePlanet\PluginCore\State\ValidatesStateTransitions;

enum State: string
{
    use ValidatesStateTransitions;

    case CREATE = 'CREATE';
    case SCHEDULED = 'SCHEDULED';
    case PENDING = 'PENDING';
    case MANUAL_CHECK = 'MANUAL_CHECK';
    case FAILED = 'FAILED';
    case SUCCESSFUL = 'SUCCESSFUL';

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
}
