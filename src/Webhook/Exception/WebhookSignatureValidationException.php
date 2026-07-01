<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook\Exception;

use WeArePlanet\PluginCore\SharedKernel\AbstractDomainException;

/**
 * Thrown when webhook signature verification fails due to API or network errors.
 */
class WebhookSignatureValidationException extends AbstractDomainException
{
}
