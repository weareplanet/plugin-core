<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\SharedKernel;

use WeArePlanet\PluginCore\Localization\LocalizedString;

abstract class AbstractDomainException extends \Exception
{
    private LocalizedString $localizedMessage;

    /**
     * Whether retrying the same operation is likely to succeed.
     *
     * Defaults to false: an exception is assumed terminal (e.g. a business-rule
     * violation) unless a subclass declares otherwise, or a caller identifies
     * the underlying cause as transient via {@see withRetryable()}.
     */
    protected bool $retryable = false;

    public function __construct(
        string $technicalMessage,
        ?LocalizedString $localizedMessage = null,
        ?\Throwable $previous = null,
    ) {
        $code = $previous !== null ? $previous->getCode() : 0;
        $this->localizedMessage = $localizedMessage ?? new LocalizedString($technicalMessage);
        parent::__construct($technicalMessage, (int)$code, $previous);
    }

    public function getLocalizedMessage(): LocalizedString
    {
        return $this->localizedMessage;
    }

    /**
     * Whether retrying the same operation is likely to succeed.
     *
     * True for transient failures (e.g. a network timeout or a concurrent
     * modification/version conflict). False for terminal failures (e.g. a
     * business-rule or validation failure) that will not succeed on retry
     * without changing the request.
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * Overrides the retryability of this exception instance.
     *
     * Intended for call sites that catch a lower-level exception and can
     * identify it as transient (e.g. a connection error) before wrapping it
     * into a domain exception.
     */
    public function withRetryable(bool $retryable = true): static
    {
        $this->retryable = $retryable;

        return $this;
    }
}
