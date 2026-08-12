<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV1;

use WeArePlanet\PluginCore\Charge\ChargeGatewayInterface;
use WeArePlanet\PluginCore\Charge\Exception\ChargeException;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\ChargeAttemptMapperTrait;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\TransactionMapperTrait;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\Sdk\Model\ChargeAttempt as SdkChargeAttempt;
use WeArePlanet\Sdk\Model\CriteriaOperator as SdkCriteriaOperator;
use WeArePlanet\Sdk\Model\EntityQuery as SdkEntityQuery;
use WeArePlanet\Sdk\Model\EntityQueryFilter as SdkEntityQueryFilter;
use WeArePlanet\Sdk\Model\EntityQueryFilterType as SdkEntityQueryFilterType;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;
use WeArePlanet\Sdk\Service\ChargeAttemptService as SdkChargeAttemptService;
use WeArePlanet\Sdk\Service\ChargeFlowService as SdkChargeFlowService;

/**
 * Gateway for charging transactions and reading charge attempts using the SDK.
 *
 * This SDK searches with an entity query object, and exposes the charge flow on a
 * service of its own. Converting SDK models into domain entities is the mapper
 * traits' job; this class owns the calls, their observability and their failure
 * handling.
 */
#[LogContext(domain: 'charge')]
class ChargeGateway implements ChargeGatewayInterface
{
    use ChargeAttemptMapperTrait;
    use DomainLoggerTrait;
    use TransactionMapperTrait;
    private SdkChargeFlowService $chargeFlowService;

    private SdkChargeAttemptService $service;

    /**
     * @param SdkProvider $sdkProvider The SDK provider.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
        $this->service = $this->sdkProvider->getService(SdkChargeAttemptService::class);
        $this->chargeFlowService = $this->sdkProvider->getService(SdkChargeFlowService::class);
    }

    /**
     * @inheritDoc
     */
    public function applyFlow(int $spaceId, int $transactionId): Transaction
    {
        $operation = 'applyFlow';
        $context = ['spaceId' => $spaceId, 'transactionId' => $transactionId];

        $this->logger->debug('Calling charge operation.', ['operation' => $operation] + $context);

        try {
            $response = $this->chargeFlowService->applyFlow($spaceId, $transactionId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Charge operation failed.',
                ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e] + $context,
            );

            throw SdkProvider::wrapException(
                $e,
                ChargeException::class,
                $operation,
                $context,
                'An error occurred while processing the charge.',
            );
        }

        $this->validateTransactionResponse($response, $operation, $context);

        return $this->mapToTransaction($response);
    }

    /**
     * Builds a leaf filter for the given field.
     *
     * @param string $fieldName The (possibly nested) field name to filter on.
     * @param mixed $value The value the field must match.
     * @param string $operator The criteria operator to apply.
     * @return SdkEntityQueryFilter The configured filter.
     */
    private function createFilter(
        string $fieldName,
        mixed $value,
        string $operator = SdkCriteriaOperator::EQUALS,
    ): SdkEntityQueryFilter {
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
    public function findAllAttemptsByTransaction(int $spaceId, int $transactionId): array
    {
        $operation = 'search';
        $context = ['spaceId' => $spaceId, 'transactionId' => $transactionId];

        // Filtered by transaction only: which attempt matters is the domain's call,
        // so no state criterion is applied here.
        $query = new SdkEntityQuery();
        $query->setFilter($this->createFilter('charge.transaction.id', $transactionId));

        $this->logger->debug('Calling charge operation.', ['operation' => $operation] + $context);

        try {
            $results = $this->service->search($spaceId, $query);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Charge operation failed.',
                ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e] + $context,
            );

            throw SdkProvider::wrapException(
                $e,
                ChargeException::class,
                $operation,
                $context,
                'An error occurred while processing the charge.',
            );
        }

        if (!is_array($results)) {
            $this->logger->error(
                'Charge operation returned an unexpected response.',
                ['operation' => $operation, 'responseType' => get_debug_type($results)] + $context,
            );

            throw SdkProvider::unexpectedResponseException(
                ChargeException::class,
                $operation,
                $context,
                'An error occurred while processing the charge.',
            );
        }

        if ($results === []) {
            // A transaction with no charge attempts is an ordinary outcome: it may
            // simply not have been charged yet. Logged at debug for that reason —
            // nothing happened that an operator needs told about.
            $this->logger->debug(
                'No charge attempts found for the transaction.',
                ['operation' => $operation] + $context,
            );

            return [];
        }

        $attempts = [];

        foreach ($results as $sdkChargeAttempt) {
            $this->validateChargeAttemptResponse($sdkChargeAttempt, $operation, $context);
            $attempts[] = $this->mapToChargeAttempt($sdkChargeAttempt);
        }

        return $attempts;
    }

    /**
     * Validates that a raw SDK response is a charge attempt.
     *
     * @param mixed $result The raw SDK response.
     * @param string $operation The SDK operation name, for log context.
     * @param array<string, mixed> $context Identifying context for the log records.
     * @return void
     * @throws ChargeException If the response was not a charge attempt.
     *
     * @phpstan-assert SdkChargeAttempt $result
     */
    private function validateChargeAttemptResponse(mixed $result, string $operation, array $context): void
    {
        if (!$result instanceof SdkChargeAttempt) {
            $this->logger->error(
                'Charge operation returned an unexpected response.',
                ['operation' => $operation, 'responseType' => get_debug_type($result)] + $context,
            );

            throw SdkProvider::unexpectedResponseException(
                ChargeException::class,
                $operation,
                $context,
                'An error occurred while processing the charge.',
            );
        }
    }

    /**
     * Validates that a raw SDK response is a transaction.
     *
     * @param mixed $result The raw SDK response.
     * @param string $operation The SDK operation name, for log context.
     * @param array<string, mixed> $context Identifying context for the log records.
     * @return void
     * @throws ChargeException If the response was not a transaction.
     *
     * @phpstan-assert SdkTransaction $result
     */
    private function validateTransactionResponse(mixed $result, string $operation, array $context): void
    {
        if (!$result instanceof SdkTransaction) {
            $this->logger->error(
                'Charge operation returned an unexpected response.',
                ['operation' => $operation, 'responseType' => get_debug_type($result)] + $context,
            );

            throw SdkProvider::unexpectedResponseException(
                ChargeException::class,
                $operation,
                $context,
                'An error occurred while processing the charge.',
            );
        }
    }
}
