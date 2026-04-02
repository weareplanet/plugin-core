<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Exception;

class TransactionTotalNegativeException extends TransactionException
{
    public function __construct(string $message = "Transaction total cannot be negative.", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
