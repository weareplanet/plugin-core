<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Invoice;

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
                self::CREATE->value     => [self::OPEN->value, self::NOT_APPLICABLE->value, self::OVERDUE->value, self::PAID->value],
                self::OPEN->value       => [self::OVERDUE->value, self::PAID->value, self::DERECOGNIZED->value, self::CANCELED->value],
                self::OVERDUE->value    => [self::PAID->value, self::DERECOGNIZED->value, self::CANCELED->value],
            ],
            'final' => [
                self::CANCELED->value,
                self::PAID->value,
                self::DERECOGNIZED->value,
                self::NOT_APPLICABLE->value,
            ],
            'any_to' => [
                self::DERECOGNIZED->value,
                self::CANCELED->value,
            ],
            'sequence' => [
                self::CREATE->value,
                self::OPEN->value,
                self::OVERDUE->value,
                self::PAID->value,
            ],
        ];
    }
    case CANCELED = 'CANCELED';

    case CREATE = 'CREATE';
    case DERECOGNIZED = 'DERECOGNIZED';
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
    case OPEN = 'OPEN';
    case OVERDUE = 'OVERDUE';
    case PAID = 'PAID';
}
