<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\GlobalData\Exception;

use WeArePlanet\PluginCore\SharedKernel\AbstractDomainException;

/**
 * Thrown when a global reference-data lookup fails at the API or transport level.
 *
 * One exception type covers all five lookups: they share a gateway, a failure mode
 * (the reference data could not be read) and a caller response (fall back to
 * cached or configured values, or surface the failure), so distinguishing them by
 * type would give a caller nothing to act on that the message does not already
 * carry.
 */
class GlobalDataException extends AbstractDomainException
{
}
