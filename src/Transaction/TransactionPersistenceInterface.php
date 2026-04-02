<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

interface TransactionPersistenceInterface
{
    /**
     * Persist the transaction ID to the storage (Session, DB, etc).
     * This MUST happen immediately.
     */
    public function persist(int $transactionId): void;
}
