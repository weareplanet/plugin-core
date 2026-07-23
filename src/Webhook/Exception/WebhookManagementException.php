<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook\Exception;

use WeArePlanet\PluginCore\SharedKernel\AbstractDomainException;

/**
 * Base exception for errors that occur while managing webhook URLs and listeners.
 */
class WebhookManagementException extends AbstractDomainException
{
}
