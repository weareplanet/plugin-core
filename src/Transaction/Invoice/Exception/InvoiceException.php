<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Invoice\Exception;

use WeArePlanet\PluginCore\SharedKernel\AbstractDomainException;

/**
 * Base exception for errors that occur while reading transaction invoices.
 */
class InvoiceException extends AbstractDomainException
{
}
