<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund;

interface RefundGatewayInterface
{
    /**
     * @return Refund[]
     */
    public function findByTransaction(int $spaceId, int $transactionId): array;
    public function refund(int $spaceId, RefundContext $context): Refund;
}
