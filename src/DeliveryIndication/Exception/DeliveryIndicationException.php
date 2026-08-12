<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\DeliveryIndication\Exception;

use WeArePlanet\PluginCore\SharedKernel\AbstractDomainException;

/**
 * Thrown when a delivery indication operation fails at the API or transport level.
 */
class DeliveryIndicationException extends AbstractDomainException
{
}
