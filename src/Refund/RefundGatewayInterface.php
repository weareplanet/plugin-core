<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund;

interface RefundGatewayInterface
{
    public function findByTransaction(int $spaceId, int $transactionId): RefundCollection;
    public function refund(int $spaceId, RefundContext $context): Refund;
}
