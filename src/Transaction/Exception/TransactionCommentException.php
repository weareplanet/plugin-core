<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Exception;

use WeArePlanet\PluginCore\Localization\LocalizedString;

/**
 * Thrown when transaction comment operations fail at the API or transport level.
 */
class TransactionCommentException extends TransactionException
{
    /**
     * Constructs a new TransactionCommentException.
     *
     * @param string $message
     * @param LocalizedString|null $localizedReason
     * @param int $code
     * @param \Throwable|null $previous
     */
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
     *
     * @return LocalizedString|null
     */
    public function getLocalizedReason(): ?LocalizedString
    {
        return $this->localizedReason;
    }
}
