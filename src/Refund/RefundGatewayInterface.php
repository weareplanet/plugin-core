<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund;

interface RefundGatewayInterface
{
    /**
     * Fetches a single refund by its ID.
     *
     * Useful for webhook processing, where the payload carries a refund ID
     * but no transaction ID.
     *
     * @param int $spaceId The space ID.
     * @param int $refundId The refund ID.
     * @return Refund The refund.
     */
    public function findById(int $spaceId, int $refundId): Refund;
    public function findByTransaction(int $spaceId, int $transactionId): RefundCollection;
    public function refund(int $spaceId, RefundContext $context): Refund;
}
