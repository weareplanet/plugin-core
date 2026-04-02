<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\PaymentMethod;

use WeArePlanet\PluginCore\Log\LoggerInterface;

/**
 * Service for managing payment methods.
 */
class PaymentMethodService
{
    /**
     * @param PaymentMethodGatewayInterface $gateway The gateway to fetch payment methods.
     * @param PaymentMethodRepositoryInterface $repository The repository to store payment methods.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly PaymentMethodGatewayInterface $gateway,
        private readonly PaymentMethodRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Retrieves a specific payment method by its ID.
     *
     * @param int $spaceId The ID of the space.
     * @param int $paymentMethodId The ID of the payment method.
     * @return PaymentMethod The payment method.
     * @throws \Exception If the payment method cannot be fetched.
     */
    public function getPaymentMethod(int $spaceId, int $paymentMethodId): PaymentMethod
    {
        try {
            return $this->gateway->fetchById($spaceId, $paymentMethodId);
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error fetching payment method %d for space %d: %s', $paymentMethodId, $spaceId, $e->getMessage()));
            throw $e;
        }
    }

    /**
     * Retrieves available payment methods for a specific space.
     *
     * @param int $spaceId The ID of the space.
     * @param string|null $state The optional state to filter by.
     * @return PaymentMethod[] The list of available payment methods.
     */
    public function getPaymentMethods(int $spaceId, ?string $state = null): array
    {
        try {
            $methods = $this->gateway->fetchBySpaceId($spaceId, $state);
            $this->logger->info(sprintf('Fetched %d payment methods for space %d.', count($methods), $spaceId));

            return $methods;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error fetching payment methods for space %d: %s', $spaceId, $e->getMessage()));

            throw $e;
        }
    }

    /**
     * Synchronizes payment methods from the gateway to the repository.
     *
     * @param int $spaceId The ID of the space.
     */
    public function synchronize(int $spaceId): void
    {
        $this->logger->debug("Starting payment method synchronization for Space $spaceId.");

        try {
            // Retrieve the latest configurations from the Gateway.
            $configurations = $this->gateway->fetchBySpaceId($spaceId);

            // Sync the fetched configurations to the local repository.
            $this->repository->sync($spaceId, $configurations);

            $this->logger->debug(sprintf("Synchronized %d payment methods.", count($configurations)));
        } catch (\Exception $e) {
            $this->logger->error("Synchronization failed: " . $e->getMessage());
            throw $e;
        }
    }
}
