<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction\Completion;

use WeArePlanet\PluginCore\Transaction\Completion\Exception\CompletionException;
use WeArePlanet\PluginCore\Transaction\Void\TransactionVoid;

/**
 * Gateway interface for transaction completion operations.
 *
 * Implementations interact with the SDK to perform capture operations.
 */
interface TransactionCompletionGatewayInterface
{
    /**
     * Captures an authorized transaction by creating a completion, fully or partially.
     *
     * Pass no request (or a request with an empty line item collection) to
     * capture the full remaining authorized amount. Pass a
     * {@see CaptureRequest} with specific line items to capture only part
     * of the transaction — e.g. when a shipment fulfills part of an order.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID to capture.
     * @param CaptureRequest|null $request The capture details, or null for a full capture.
     * @return TransactionCompletion The resulting completion domain object.
     * @throws CompletionException If the capture fails.
     */
    public function capture(int $spaceId, int $transactionId, ?CaptureRequest $request = null): TransactionCompletion;

    /**
     * Finds a completion by ID.
     *
     * @param int $spaceId The space ID.
     * @param int $completionId The completion ID.
     * @return TransactionCompletion|null The completion, or null if not found.
     * @throws CompletionException If the API request fails (non-404).
     */
    public function find(int $spaceId, int $completionId): ?TransactionCompletion;

    /**
     * Gets a completion by ID and throws if not found or failed.
     *
     * @param int $spaceId The space ID.
     * @param int $completionId The completion ID.
     * @return TransactionCompletion The completion.
     * @throws CompletionException If the completion is not found or the request fails.
     */
    public function get(int $spaceId, int $completionId): TransactionCompletion;

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
