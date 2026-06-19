<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Completion;

use WeArePlanet\PluginCore\Transaction\Void\TransactionVoid;

/**
 * Gateway interface for transaction completion operations.
 *
 * Implementations interact with the SDK to perform capture operations.
 */
interface TransactionCompletionGatewayInterface
{
    /**
     * Captures an authorized transaction by creating a completion.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID to capture.
     * @return TransactionCompletion The resulting completion domain object.
     */
    public function capture(int $spaceId, int $transactionId): TransactionCompletion;

    /**
     * Voids an authorized transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID to void.
     * @return TransactionVoid The resulting void domain object.
     */
    public function void(
        int $spaceId,
        int $transactionId,
    ): TransactionVoid;
}
