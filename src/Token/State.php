<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Token;

use WeArePlanet\PluginCore\State\ValidatesStateTransitions;
use WeArePlanet\Sdk\Model\CreationEntityState as SdkCreationEntityState;

/**
 * Represents the state of a token.
 *
 * @see SdkCreationEntityState
 */
enum State: string
{
    use ValidatesStateTransitions;

    case ACTIVE = 'ACTIVE';
    case CREATE = 'CREATE';
    case DELETED = 'DELETED';
    case DELETING = 'DELETING';
    case INACTIVE = 'INACTIVE';

    public static function getTransitionMap(): array
    {
        return [
            'initial' => [
                self::CREATE->value,
            ],
            'transitions' => [
                self::CREATE->value   => [self::ACTIVE->value, self::INACTIVE->value],
                self::ACTIVE->value   => [self::INACTIVE->value, self::DELETING->value, self::DELETED->value],
                self::INACTIVE->value => [self::ACTIVE->value, self::DELETING->value, self::DELETED->value],
                self::DELETING->value => [self::DELETED->value],
            ],
            'final' => [
                self::DELETED->value,
            ],
            'any_to' => [
                self::DELETED->value,
            ],
            'sequence' => [
                self::CREATE->value,
                self::ACTIVE->value,
                self::INACTIVE->value,
                self::DELETING->value,
                self::DELETED->value,
            ],
        ];
    }
}
