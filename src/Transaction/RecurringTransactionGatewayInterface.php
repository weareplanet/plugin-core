<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

/**
 * Interface RecurringTransactionGatewayInterface
 *
 * Handles the processing of recurring payments (MIT) via the gateway.
 */
interface RecurringTransactionGatewayInterface
{
    /**
     * Processes a recurring payment for an existing transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return Transaction The processed transaction.
     */
    public function processRecurringPayment(int $spaceId, int $transactionId): Transaction;
}
