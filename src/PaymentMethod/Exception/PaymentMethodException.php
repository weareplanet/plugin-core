<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\PaymentMethod\Exception;

use WeArePlanet\PluginCore\SharedKernel\AbstractDomainException;

/**
 * Base exception for errors that occur while fetching payment methods.
 */
class PaymentMethodException extends AbstractDomainException
{
}
