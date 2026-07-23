<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\PaymentMethod;

use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodCollection;

/**
 * Service for managing payment methods.
 */
#[LogContext(domain: 'sync')]
class PaymentMethodService
{
    use DomainLoggerTrait;
    /**
     * @param PaymentMethodGatewayInterface $gateway The gateway to fetch payment methods.
     * @param PaymentMethodRepositoryInterface $repository The repository to store payment methods.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly PaymentMethodGatewayInterface $gateway,
        private readonly PaymentMethodRepositoryInterface $repository,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * Retrieves a specific payment method by its ID.
     *
     * @param int $spaceId The ID of the space.
     * @param int $paymentMethodId The ID of the payment method.
     * @return PaymentMethod The payment method.
     * @throws \Exception If the payment method cannot be fetched.
     */
    public function getPaymentMethod(
        int $spaceId,
        int $paymentMethodId,
    ): PaymentMethod {
        try {
            return $this->gateway->fetchById(
                $spaceId,
                $paymentMethodId,
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Error fetching payment method.',
                [
                    'paymentMethodId' => $paymentMethodId,
                    'spaceId' => $spaceId,
                    'exception' => $e,
                ],
            );
            throw $e;
        }
    }

    /**
     * Retrieves available payment methods for a specific space.
     *
     * @param int $spaceId The ID of the space.
     * @param string|null $state The optional state to filter by.
     * @return PaymentMethodCollection The list of available payment methods.
     */
    public function getPaymentMethods(
        int $spaceId,
        ?string $state = null,
    ): PaymentMethodCollection {
        try {
            $methods = $this->gateway->fetchBySpaceId(
                $spaceId,
                $state,
            );
            $this->logger->info(
                'Fetched payment methods.',
                [
                    'count' => count($methods),
                    'spaceId' => $spaceId,
                ],
            );

            return $methods;
        } catch (\Exception $e) {
            $this->logger->error(
                'Error fetching payment methods.',
                [
                    'spaceId' => $spaceId,
                    'exception' => $e,
                ],
            );

            throw $e;
        }
    }

    /**
     * Synchronizes payment methods from the API gateway into the local database.
     *
     * This method owns the diffing algorithm: it compares the external API state
     * against the locally persisted IDs and delegates granular create/update/deactivate
     * operations to the repository. Client plugins never need to implement this logic.
     *
     * @param int $spaceId The ID of the space to synchronize.
     * @throws \Exception If the gateway request fails; repository methods are never called in that case.
     */
    public function synchronize(
        int $spaceId,
    ): void {
        $this->logger->debug(
            'Starting payment method synchronization.',
            ['spaceId' => $spaceId],
        );

        // Fetch the current state from the API.
        $externalMethods = $this->gateway->fetchBySpaceId(
            $spaceId,
        );
        $this->logger->debug(
            'Fetched payment methods from the API.',
            [
                'count' => count($externalMethods),
                'spaceId' => $spaceId,
            ],
        );

        // Fetch the IDs of methods already persisted in the shop's local database.
        // We support both a simple list of IDs [id1, id2] and an associative map [id => signature].
        /** @var array<int, int|string> $existingData */
        $existingData = $this->repository->getExistingExternalIds(
            $spaceId,
        );
        $this->logger->debug(
            'Found existing payment method records in the local database.',
            [
                'count' => count($existingData),
                'spaceId' => $spaceId,
            ],
        );

        // Detect if the repository provided signatures for smart comparison.
        $hasSignatures = !empty($existingData) && \is_string(\reset($existingData)) && \is_int(\key($existingData));

        // Track which external IDs were processed to detect orphans afterwards.
        $processedIds = [];
        $createdCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;

        // Diff each API method against the local state.
        foreach ($externalMethods as $method) {
            $processedIds[] = $method->id;

            $exists = $hasSignatures ? isset($existingData[$method->id]) : \in_array($method->id, $existingData, true);

            if ($exists) {
                // If we have signatures, compare them to decide if an update is actually needed.
                if ($hasSignatures && $existingData[$method->id] === $method->getSignature()) {
                    $skippedCount++;
                    continue;
                }

                $this->repository->update(
                    $method,
                    $spaceId,
                );
                $updatedCount++;
            } else {
                $this->repository->create(
                    $method,
                    $spaceId,
                );
                $createdCount++;
            }
        }

        // Orphans are local methods that the API no longer returns.
        // We extract the actual IDs for comparison.
        $existingIds = $hasSignatures ? \array_keys($existingData) : $existingData;
        $orphanedIds = \array_diff($existingIds, $processedIds);
        foreach ($orphanedIds as $orphanedId) {
            $this->repository->deactivateByExternalId(
                $orphanedId,
                $spaceId,
            );
        }

        $this->logger->info(
            'Payment method sync completed.',
            [
                'spaceId' => $spaceId,
                'created' => $createdCount,
                'updated' => $updatedCount,
                'skipped' => $skippedCount,
                'deactivated' => count($orphanedIds),
            ],
        );
    }
}
