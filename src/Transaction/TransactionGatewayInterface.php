<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;

interface TransactionGatewayInterface
{
    /**
     * Creates a new transaction.
     *
     * @param TransactionContext $context The transaction context.
     * @return Transaction The created transaction.
     */
    public function create(TransactionContext $context): Transaction;

    /**
     * Finds a transaction by ID.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return Transaction|null The transaction, or null if not found.
     */
    public function find(int $spaceId, int $transactionId): ?Transaction;

    /**
     * Gets a transaction by ID and throws if failed.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return Transaction The transaction.
     */
    public function get(int $spaceId, int $transactionId): Transaction;

    /**
     * Gets available payment methods for a transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return PaymentMethod[] The available payment methods.
     */
    public function getAvailablePaymentMethods(int $spaceId, int $transactionId): array;

    /**
     * Gets all active payment method configurations.
     *
     * @param int $spaceId The space ID.
     * @return PaymentMethod[] The payment method configurations.
     */
    public function getPaymentMethodConfigurations(int $spaceId): array;

    /**
     * Gets the payment URL for a transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return string The payment URL.
     */
    public function getPaymentUrl(int $spaceId, int $transactionId): string;

    /**
     * Updates an existing transaction.
     *
     * @param int $transactionId The transaction ID.
     * @param int $version The transaction version.
     * @param TransactionContext $context The transaction context.
     * @return Transaction The updated transaction.
     */
    public function update(int $transactionId, int $version, TransactionContext $context): Transaction;

    /**
     * Searches for transactions matching the criteria.
     *
     * @param int $spaceId The space ID.
     * @param TransactionSearchCriteria $criteria The search criteria.
     * @return Transaction[] The matching transactions.
     */
    public function search(int $spaceId, TransactionSearchCriteria $criteria): array;
}
