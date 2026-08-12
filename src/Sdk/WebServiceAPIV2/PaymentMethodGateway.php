<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodCollection;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodGatewayInterface;
use WeArePlanet\PluginCore\Sdk\PaymentMethodMapperTrait;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\PaymentMethod\Exception\PaymentMethodException;
use WeArePlanet\Sdk\Model\PaymentMethodConfiguration as SdkPaymentMethodConfiguration;
use WeArePlanet\Sdk\Service\PaymentMethodConfigurationsService as SdkPaymentMethodConfigurationService;

/**
 * Gateway implementation using the SDK V2.
 */
#[LogContext(domain: 'sync')]
class PaymentMethodGateway implements PaymentMethodGatewayInterface
{
    use DomainLoggerTrait;
    use PaymentMethodMapperTrait;

    /**
     * @param SdkProvider $sdkProvider The SDK provider.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * @inheritDoc
     */
    public function fetchById(int $spaceId, int $id): PaymentMethod
    {
        try {
            /** @var SdkPaymentMethodConfigurationService $service */
            $service = $this->sdkProvider->getService(SdkPaymentMethodConfigurationService::class);

            // V2: getPaymentMethodConfigurationsId($id, $space)
            $config = $service->getPaymentMethodConfigurationsId($id, $spaceId);

            return $this->mapToPaymentMethod($config);
        } catch (\Throwable $e) {
            $this->logger->error("PaymentMethodGateway: Failed to fetch payment method from SDK.", [
                'paymentMethodId' => $id,
                'spaceId' => $spaceId,
                'exception' => $e,
            ]);
            throw SdkProvider::wrapException(
                $e,
                PaymentMethodException::class,
                'read',
                ['spaceId' => $spaceId, 'paymentMethodId' => $id],
                'Payment method not found.',
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function fetchBySpaceId(int $spaceId, ?string $state = null): PaymentMethodCollection
    {
        try {
            /** @var SdkPaymentMethodConfigurationService $service */
            $service = $this->sdkProvider->getService(SdkPaymentMethodConfigurationService::class);

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
            return new PaymentMethodCollection(...array_map([$this, 'mapToPaymentMethod'], $items));
        } catch (\Throwable $e) {
            $this->logger->error("PaymentMethodGateway: Failed to fetch payment methods from SDK.", [
                'spaceId' => $spaceId,
                'exception' => $e,
            ]);
            throw SdkProvider::wrapException(
                $e,
                PaymentMethodException::class,
                'search',
                ['spaceId' => $spaceId],
                'Unable to fetch payment methods.',
            );
        }
    }

}
