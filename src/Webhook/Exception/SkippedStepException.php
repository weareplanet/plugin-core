<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook\Exception;

/**
 * Thrown when a webhook processing step is skipped intentionally
 * (e.g., due to a race condition or idempotency check).
 */
class SkippedStepException extends \Exception
{
}
