<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodGatewayInterface;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\Sdk\Model\PaymentMethodConfiguration as SdkPaymentMethodConfiguration;
use WeArePlanet\Sdk\Service\PaymentMethodConfigurationsService as SdkPaymentMethodConfigurationService;

/**
 * Gateway implementation using the SDK V2.
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
     * @inheritDoc
     */
    public function fetchById(int $spaceId, int $id): PaymentMethod
    {
        try {
            /** @var SdkPaymentMethodConfigurationService $service */
            $service = $this->provider->getService(SdkPaymentMethodConfigurationService::class);

            // V2: getPaymentMethodConfigurationsId($id, $space)
            $config = $service->getPaymentMethodConfigurationsId($id, $spaceId);

            return $this->mapToEntity($config);
        } catch (\Throwable $e) {
            $this->logger->error("PaymentMethodGateway: Failed to fetch payment method $id from SDK: {$e->getMessage()}");
            throw new TransactionException("Payment method $id not found: {$e->getMessage()}", 0, $e);
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

            // V2 Search: query string
            $query = null;
            if ($state !== null) {
                // Filter by a specific state.
                $query = "state:$state";
            } else {
                // By default, exclude deleted payment methods to match V1 behavior.
                // In V2 query syntax, prepending '-' to a field name excludes the value.
                $query = "-state:DELETED";
            }

            // getPaymentMethodConfigurationsSearch signature: ($space, $expand, $limit, $offset, $order, $query)
            // We pass null for expand/limit/offset/order, and use query.
            $results = $service->getPaymentMethodConfigurationsSearch($spaceId, null, null, null, null, $query);

            $items = (is_object($results) && method_exists($results, 'getData')) ? $results->getData() : (array)$results;
            return array_map(fn (SdkPaymentMethodConfiguration $config) => $this->mapToEntity($config), $items);
        } catch (\Throwable $e) {
            $this->logger->error("PaymentMethodGateway: Failed to fetch payment methods from SDK: {$e->getMessage()}");
            throw new TransactionException("Unable to fetch payment methods: {$e->getMessage()}", 0, $e);
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
            id: (int)$config->getId(),
            spaceId: (int)$config->getLinkedSpaceId(),
            state: (string)$config->getState(),
            name: $this->resolveLocalization($config->getResolvedTitle() ?? $config->getName()),
            title: $config->getResolvedTitle() ?? [],
            description: $this->resolveLocalization($config->getResolvedDescription() ?? $config->getDescription()),
            descriptionMap: $config->getResolvedDescription() ?? $config->getDescription() ?? [],
            sortOrder: (int)$config->getSortOrder(),
            imageUrl: $config->getResolvedImageUrl(),
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
        if (!is_array($input)) {
            return $input;
        }

        return $input['en-US'] ?? $input['en-GB'] ?? reset($input) ?: null;
    }
}
