<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Token\Exception;

use WeArePlanet\PluginCore\Localization\LocalizedString;

/**
 * Thrown when a token is expected but missing from the transaction.
 *
 * Ensures fail-fast behavior instead of attempting silent token recovery.
 */
class MissingTokenException extends TokenException
{
    /**
     * Constructs a new MissingTokenException.
     *
     * @param string $message
     * @param LocalizedString|null $localizedReason
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message = '',
        ?LocalizedString $localizedReason = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message,
            $localizedReason ?? new LocalizedString([
                'en-US' => 'Required token is missing from the transaction.',
            ]),
            $code,
            $previous,
        );
    }
}
