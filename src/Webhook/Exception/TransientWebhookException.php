<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook\Exception;

use WeArePlanet\PluginCore\SharedKernel\AbstractDomainException;

/**
 * Signals a temporary, self-healing failure during webhook processing
 * (e.g. a lock contention timeout under concurrent deliveries).
 *
 * Consumers throw this instead of a generic exception to tell the core
 * that the standard WeArePlanet Portal retry will recover the situation, so it is
 * logged at a low severity instead of as an error.
 */
class TransientWebhookException extends AbstractDomainException
{
    protected bool $retryable = true;
}
