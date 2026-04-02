<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

interface TransactionCommentGatewayInterface
{
    /**
     * Gets comments for a transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return TransactionComment[] The list of comments.
     */
    public function getComments(int $spaceId, int $transactionId): array;
}
