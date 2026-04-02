<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Completion;

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
     * @return string The state of the void operation.
     */
    public function void(int $spaceId, int $transactionId): string;
}
