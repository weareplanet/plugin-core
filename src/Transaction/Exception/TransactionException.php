<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Exception;

use WeArePlanet\PluginCore\SharedKernel\AbstractDomainException;

/**
 * Thrown when the transaction creation or update process fails at the logical or API level.
 */
class TransactionException extends AbstractDomainException
{
    /**
     * Whether another request changed the same transaction at the same moment.
     */
    private bool $conflict = false;

    /**
     * Whether an update was rejected because another request changed the same transaction at
     * the same moment.
     *
     * Reading the transaction again and repeating the update is expected to succeed, unlike
     * other retryable failures such as a lost connection, which say nothing about the
     * transaction itself.
     */
    public function isConflict(): bool
    {
        return $this->conflict;
    }

    /**
     * Marks this exception as a conflict with another request. A conflict is always retryable.
     */
    public function withConflict(): static
    {
        $this->conflict = true;

        return $this->withRetryable(true);
    }
}
