<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Token\Exception;

use WeArePlanet\PluginCore\Localization\LocalizedString;

/**
 * Thrown when token creation fails at the API or transport level.
 *
 * Carries a localized reason so consumers can surface the gateway's rejection
 * message in the shop locale instead of silently dropping it.
 */
class TokenException extends \Exception
{
    public function __construct(
        string $message = '',
        private readonly ?LocalizedString $localizedReason = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The localized failure reason, when one is available.
     */
    public function getLocalizedReason(): ?LocalizedString
    {
        return $this->localizedReason;
    }
}
