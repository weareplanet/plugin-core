<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV1;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodGatewayInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\Sdk\Model\CreationEntityState as SdkCreationEntityState;
use WeArePlanet\Sdk\Model\CriteriaOperator as SdkCriteriaOperator;
use WeArePlanet\Sdk\Model\EntityQuery as SdkEntityQuery;
use WeArePlanet\Sdk\Model\EntityQueryFilter as SdkEntityQueryFilter;
use WeArePlanet\Sdk\Model\EntityQueryFilterType as SdkEntityQueryFilterType;
use WeArePlanet\Sdk\Model\PaymentMethodConfiguration as SdkPaymentMethodConfiguration;
use WeArePlanet\Sdk\Service\PaymentMethodConfigurationService as SdkPaymentMethodConfigurationService;

/**
 * Gateway implementation using the SDK.
 */
class PaymentMethodGateway implements PaymentMethodGatewayInterface
{
    /**
     * @param SdkProvider $provider The SDK provider.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly SdkProvider $provider,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Helper to create an SDK filter.
     *
     * @param string $fieldName The field to filter on.
     * @param mixed $value The value to filter by.
     * @param string $operator The operator to use (defaults to EQUALS).
     * @return SdkEntityQueryFilter The created filter.
     */
    private function createFilter(string $fieldName, mixed $value, string $operator = SdkCriteriaOperator::EQUALS): SdkEntityQueryFilter
    {
        $filter = new SdkEntityQueryFilter();
        $filter->setType(SdkEntityQueryFilterType::LEAF);
        $filter->setOperator($operator);
        $filter->setFieldName($fieldName);
        $filter->setValue($value);
        return $filter;
    }

    /**
     * @inheritDoc
     */
    public function fetchById(int $spaceId, int $id): PaymentMethod
    {
        try {
            /** @var SdkPaymentMethodConfigurationService $service */
            $service = $this->provider->getService(SdkPaymentMethodConfigurationService::class);

            $config = $service->read($spaceId, $id);

            return $this->mapToEntity($config);
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to fetch payment method %d from SDK: %s', $id, $e->getMessage()));
            throw new \RuntimeException(sprintf('Payment method %d not found.', $id), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function fetchBySpaceId(int $spaceId, ?string $state = null): array
    {
        try {
            /** @var SdkPaymentMethodConfigurationService $service */
            $service = $this->provider->getService(SdkPaymentMethodConfigurationService::class);

            $query = new SdkEntityQuery();

            if ($state !== null) {
                $query->setFilter($this->createFilter('state', $state));
            } else {
                // By default, we exclude deleted payment methods as they are usually not relevant
                // for active operations.
                $query->setFilter($this->createFilter('state', SdkCreationEntityState::DELETED, SdkCriteriaOperator::NOT_EQUALS));
            }

            $results = $service->search($spaceId, $query);

            return array_map(fn (SdkPaymentMethodConfiguration $config) => $this->mapToEntity($config), $results);
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed to fetch payment methods from SDK: %s', $e->getMessage()));
            throw $e;
        }
    }

    /**
     * Maps an SDK configuration to a domain entity.
     *
     * @param SdkPaymentMethodConfiguration $config The SDK configuration.
     * @return PaymentMethod The domain entity.
     */
    private function mapToEntity(SdkPaymentMethodConfiguration $config): PaymentMethod
    {
        return new PaymentMethod(
            id: $config->getId(),
            spaceId: $config->getLinkedSpaceId(),
            state: (string) $config->getState(), // Cast to string as state is usually an enum or string
            //TODO: We need to check how to support different language codes.
            name: $this->resolveLocalization($config->getResolvedTitle() ?? $config->getName()),
            title: $config->getResolvedTitle() ?? [],
            description: $this->resolveLocalization($config->getResolvedDescription() ?? $config->getDescription()),
            descriptionMap: $config->getResolvedDescription() ?? $config->getDescription() ?? [],
            sortOrder: $config->getSortOrder(),
            imageUrl: $config->getResolvedImageUrl(), // Assuming this exists or similar
        );
    }

    /**
     * Resolves a localized string (which might be an array) to a single string.
     *
     * @param array<string, string>|string|null $input
     * @return string|null
     */
    private function resolveLocalization(array|string|null $input): ?string
    {
        if (is_string($input) || is_null($input)) {
            return $input;
        }

        if (is_array($input)) {
            // Prefer English, fallback to first available
            return $input['en-US'] ?? $input['en-GB'] ?? reset($input) ?: null;
        }

        return null;
    }
}
