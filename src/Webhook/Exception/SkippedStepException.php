<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook\Exception;

use WeArePlanet\PluginCore\SharedKernel\AbstractDomainException;

/**
 * Thrown when a webhook processing step is skipped intentionally.
 */
class SkippedStepException extends AbstractDomainException
{
}
