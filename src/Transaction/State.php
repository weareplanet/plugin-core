<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

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
                self::CREATE->value    => [self::PENDING->value, self::CONFIRMED->value],
                self::PENDING->value    => [self::CONFIRMED->value, self::FAILED->value],
                self::CONFIRMED->value => [self::PROCESSING->value, self::FAILED->value],
                self::PROCESSING->value => [self::FAILED->value, self::AUTHORIZED->value],
                self::AUTHORIZED->value => [self::COMPLETED->value, self::VOIDED->value],
                self::COMPLETED->value  => [self::FULFILL->value, self::DECLINE->value],
            ],
            'final' => [
                self::FAILED->value,
                self::VOIDED->value,
                self::FULFILL->value,
                self::DECLINE->value,
            ],
            'any_to' => [
                self::FAILED->value,
                self::VOIDED->value,
            ],
            'sequence' => [
                self::CREATE->value,
                self::PENDING->value,
                self::CONFIRMED->value,
                self::PROCESSING->value,
                self::AUTHORIZED->value,
                self::COMPLETED->value,
                self::FULFILL->value,
            ],
        ];
    }
    case AUTHORIZED = 'AUTHORIZED';
    case COMPLETED = 'COMPLETED';
    case CONFIRMED = 'CONFIRMED';

    case CREATE = 'CREATE';
    case DECLINE = 'DECLINE';
    case FAILED = 'FAILED';
    case FULFILL = 'FULFILL';
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case VOIDED = 'VOIDED';
}
