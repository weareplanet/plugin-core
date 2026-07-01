<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Token\Exception;

use WeArePlanet\PluginCore\Localization\LocalizedString;

/**
 * Thrown when a token is expected but missing from the transaction.
 */
class MissingTokenException extends TokenException
{
    public function __construct(
        string $message = '',
        ?LocalizedString $localizedReason = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message,
            $localizedReason ?? new LocalizedString('Required token is missing from the transaction.'),
            $previous,
        );
    }
}
