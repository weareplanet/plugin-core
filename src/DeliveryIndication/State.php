<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\DeliveryIndication;

use WeArePlanet\PluginCore\State\ValidatesStateTransitions;

enum State: string
{
    use ValidatesStateTransitions;

    public static function getTransitionMap(): array
    {
        return [
            'initial' => [
                self::PENDING->value,
            ],
            'transitions' => [
                self::PENDING->value => [self::NOT_SUITABLE->value, self::MANUAL_CHECK_REQUIRED->value, self::SUITABLE->value],
            ],
            'final' => [
                self::NOT_SUITABLE->value,
                self::MANUAL_CHECK_REQUIRED->value,
                self::SUITABLE->value,
            ],
            'any_to' => [
                self::NOT_SUITABLE->value,
            ],
            'sequence' => [
                self::PENDING->value,
                self::SUITABLE->value,
            ],
        ];
    }
    case MANUAL_CHECK_REQUIRED = 'MANUAL_CHECK_REQUIRED';
    case NOT_SUITABLE = 'NOT_SUITABLE';

    case PENDING = 'PENDING';
    case SUITABLE = 'SUITABLE';
}
