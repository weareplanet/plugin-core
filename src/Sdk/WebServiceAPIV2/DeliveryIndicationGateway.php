<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\DeliveryIndication\DeliveryIndication;
use WeArePlanet\PluginCore\DeliveryIndication\DeliveryIndicationGatewayInterface;
use WeArePlanet\PluginCore\DeliveryIndication\Exception\DeliveryIndicationException;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\DeliveryIndicationMapperTrait;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\Sdk\Model\DeliveryIndication as SdkDeliveryIndication;
use WeArePlanet\Sdk\Model\DeliveryIndicationSearchResponse as SdkDeliveryIndicationSearchResponse;
use WeArePlanet\Sdk\Model\RestApiErrorResponse as SdkRestApiErrorResponse;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;
use WeArePlanet\Sdk\Service\DeliveryIndicationsService as SdkDeliveryIndicationsService;

/**
 * Gateway for reading and deciding delivery indications using the SDK.
 *
 * Three things differ from WebServiceAPIV1 and are absorbed here so consumers
 * never see them:
 *
 * - The single-entity operations take `($indicationId, $spaceId)` — the reverse of
 *   the domain interface's order. Both are integers, so the transposition is
 *   invisible to PHP and to static analysis; it is done in exactly one place per
 *   operation and asserted by the gateway's tests. Search is the exception: it
 *   takes the space first, like the domain interface.
 * - Operations return a union of the entity and an error response rather than
 *   throwing, and searches return a paged response wrapper.
 * - The completion is embedded as an object instead of reported as a bare ID, and
 *   searches are expressed as a query string instead of an entity query object.
 */
#[LogContext(domain: 'delivery_indication')]
class DeliveryIndicationGateway implements DeliveryIndicationGatewayInterface
{
    use DeliveryIndicationMapperTrait;
    use DomainLoggerTrait;

    private SdkDeliveryIndicationsService $service;

    /**
     * @param SdkProvider $sdkProvider The SDK provider.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
        $this->service = $this->sdkProvider->getService(SdkDeliveryIndicationsService::class);
    }

    /**
     * @inheritDoc
     */
    public function findByTransaction(int $spaceId, int $transactionId): ?DeliveryIndication
    {
        $operation = 'getPaymentDeliveryIndicationsSearch';
        $context = ['spaceId' => $spaceId, 'transactionId' => $transactionId];

        // V2 Search: 'field:value' query string rather than an entity query object.
        $query = 'transaction.id:' . $transactionId;

        $this->logger->debug('Calling delivery indication operation.', ['operation' => $operation] + $context);

        try {
            // Search takes the space first, unlike this SDK's single-entity operations.
            // A transaction has at most one delivery indication, hence the limit of 1.
            $response = $this->service->getPaymentDeliveryIndicationsSearch(
                $spaceId,
                null,
                1,
                null,
                null,
                $query,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Delivery indication operation failed.',
                ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e] + $context,
            );

            throw SdkProvider::wrapException(
                $e,
                DeliveryIndicationException::class,
                $operation,
                $context,
                'An error occurred while processing the delivery indication.',
            );
        }

        // This SDK answers some non-2xx replies with an error model instead of throwing.
        if (!$response instanceof SdkDeliveryIndicationSearchResponse) {
            $errorContext = ['operation' => $operation, 'responseType' => get_debug_type($response)];

            if ($response instanceof SdkRestApiErrorResponse) {
                $errorContext['errorMessage'] = $response->getMessage();
                $errorContext['errorCode'] = $response->getCode();
            }

            $this->logger->error(
                'Delivery indication operation returned an unexpected response.',
                $errorContext + $context,
            );

            throw SdkProvider::unexpectedResponseException(
                DeliveryIndicationException::class,
                $operation,
                $context,
                'An error occurred while processing the delivery indication.',
            );
        }

        $sdkIndication = ($response->getData() ?? [])[0] ?? null;

        if ($sdkIndication === null) {
            // A transaction with no delivery indication is an ordinary outcome: the
            // payment may not be captured, or may not have been selected for review.
            $this->logger->info(
                'No delivery indication found for the transaction.',
                ['operation' => $operation] + $context,
            );

            return null;
        }

        $this->validateDeliveryIndicationResponse($sdkIndication, $operation, $context);

        return $this->mapToDeliveryIndication($sdkIndication, $spaceId);
    }

    /**
     * @inheritDoc
     */
    public function get(int $spaceId, int $indicationId): DeliveryIndication
    {
        $operation = 'getPaymentDeliveryIndicationsId';
        $context = ['spaceId' => $spaceId, 'indicationId' => $indicationId];

        $this->logger->debug('Calling delivery indication operation.', ['operation' => $operation] + $context);

        try {
            // Argument order reversed for this SDK: indication first, space second.
            $result = $this->service->getPaymentDeliveryIndicationsId($indicationId, $spaceId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Delivery indication operation failed.',
                ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e] + $context,
            );

            throw SdkProvider::wrapException(
                $e,
                DeliveryIndicationException::class,
                $operation,
                $context,
                'An error occurred while processing the delivery indication.',
            );
        }

        $this->validateDeliveryIndicationResponse($result, $operation, $context);

        return $this->mapToDeliveryIndication($result, $spaceId);
    }

    /**
     * @inheritDoc
     */
    public function markAsNotSuitable(int $spaceId, int $indicationId): DeliveryIndication
    {
        $operation = 'postPaymentDeliveryIndicationsIdMarkNotSuitable';
        $context = ['spaceId' => $spaceId, 'indicationId' => $indicationId];

        $this->logger->debug('Calling delivery indication operation.', ['operation' => $operation] + $context);

        try {
            // Argument order reversed for this SDK: indication first, space second.
            $result = $this->service->postPaymentDeliveryIndicationsIdMarkNotSuitable($indicationId, $spaceId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Delivery indication operation failed.',
                ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e] + $context,
            );

            throw SdkProvider::wrapException(
                $e,
                DeliveryIndicationException::class,
                $operation,
                $context,
                'An error occurred while processing the delivery indication.',
            );
        }

        $this->validateDeliveryIndicationResponse($result, $operation, $context);

        $indication = $this->mapToDeliveryIndication($result, $spaceId);

        $this->logger->info(
            'Delivery indication operation succeeded.',
            ['operation' => $operation, 'state' => $indication->state->value] + $context,
        );

        return $indication;
    }

    /**
     * @inheritDoc
     */
    public function markAsSuitable(int $spaceId, int $indicationId): DeliveryIndication
    {
        $operation = 'postPaymentDeliveryIndicationsIdMarkSuitable';
        $context = ['spaceId' => $spaceId, 'indicationId' => $indicationId];

        $this->logger->debug('Calling delivery indication operation.', ['operation' => $operation] + $context);

        try {
            // Argument order reversed for this SDK: indication first, space second.
            $result = $this->service->postPaymentDeliveryIndicationsIdMarkSuitable($indicationId, $spaceId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Delivery indication operation failed.',
                ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e] + $context,
            );

            throw SdkProvider::wrapException(
                $e,
                DeliveryIndicationException::class,
                $operation,
                $context,
                'An error occurred while processing the delivery indication.',
            );
        }

        $this->validateDeliveryIndicationResponse($result, $operation, $context);

        $indication = $this->mapToDeliveryIndication($result, $spaceId);

        $this->logger->info(
            'Delivery indication operation succeeded.',
            ['operation' => $operation, 'state' => $indication->state->value] + $context,
        );

        return $indication;
    }

    /**
     * Validates that a raw SDK response is a delivery indication.
     *
     * @param mixed $result The raw SDK response.
     * @param string $operation The SDK operation name, for log context.
     * @param array<string, mixed> $context Identifying context for the log records.
     * @return void
     * @throws DeliveryIndicationException If the response was not a delivery indication.
     *
     * @phpstan-assert SdkDeliveryIndication $result
     */
    private function validateDeliveryIndicationResponse(mixed $result, string $operation, array $context): void
    {
        if (!$result instanceof SdkDeliveryIndication) {
            $errorContext = ['operation' => $operation, 'responseType' => get_debug_type($result)];

            if ($result instanceof SdkRestApiErrorResponse) {
                $errorContext['errorMessage'] = $result->getMessage();
                $errorContext['errorCode'] = $result->getCode();
            }

            $this->logger->error(
                'Delivery indication operation returned an unexpected response.',
                $errorContext + $context,
            );

            throw SdkProvider::unexpectedResponseException(
                DeliveryIndicationException::class,
                $operation,
                $context,
                'An error occurred while processing the delivery indication.',
            );
        }
    }

}
