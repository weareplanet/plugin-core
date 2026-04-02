<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\PaymentMethod;

/**
 * Interface for fetching payment methods from the infrastructure layer.
 */
interface PaymentMethodGatewayInterface
{
    /**
     * Fetches a specific payment method by its ID.
     *
     * @param int $spaceId The ID of the space.
     * @param int $id The ID of the payment method.
     * @return PaymentMethod The payment method.
     * @throws \RuntimeException If the payment method is not found.
     */
    public function fetchById(int $spaceId, int $id): PaymentMethod;

    /**
     * Fetches available payment methods for a given space.
     *
     * @param int $spaceId The ID of the space.
     * @param string|null $state Optional state to filter by.
     * @return PaymentMethod[] List of available payment methods.
     */
    public function fetchBySpaceId(int $spaceId, ?string $state = null): array;
}
