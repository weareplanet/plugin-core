<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund\Exception;

use WeArePlanet\PluginCore\Localization\LocalizedString;

/**
 * Thrown when a refund operation fails at the API or transport level.
 *
 * Carries a localized reason so consumers can surface a merchant-facing
 * message in the shop locale instead of a raw, English-only SDK exception
 * string. Business-rule validation failures use {@see InvalidRefundException}.
 */
class RefundException extends \Exception
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
