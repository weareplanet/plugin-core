<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Charge\Attempt\ChargeAttempt;
use WeArePlanet\PluginCore\Charge\ChargeGatewayInterface;
use WeArePlanet\PluginCore\Charge\Exception\ChargeException;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\ChargeAttemptMapperTrait;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\SearchPaginationTrait;
use WeArePlanet\PluginCore\Sdk\TransactionMapperTrait;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\Sdk\Model\ChargeAttempt as SdkChargeAttempt;
use WeArePlanet\Sdk\Model\ChargeAttemptSearchResponse as SdkChargeAttemptSearchResponse;
use WeArePlanet\Sdk\Model\RestApiErrorResponse as SdkRestApiErrorResponse;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;
use WeArePlanet\Sdk\Service\ChargeAttemptsService as SdkChargeAttemptsService;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;

/**
 * Gateway for charging transactions and reading charge attempts using the SDK.
 *
 * Unlike WebServiceAPIV1, this SDK version has no entity query object — search
 * criteria are expressed as a `field:value` query string, with terms separated
 * by spaces to combine them conjunctively — and it reports some failures by
 * returning an error model rather than throwing, so the response of every call is
 * checked against its expected model before anything else sees it.
 *
 * This API also places the charge flow on its transaction service rather than on a
 * charge flow service of its own, and takes the transaction ID before the space ID.
 * Both differences stop at this class: the domain contract is the same on either
 * API version.
 *
 * Converting SDK models into domain entities is the mapper traits' job — including
 * the payload-shape differences behind those entities; this class owns the calls,
 * their observability and their failure handling.
 */
#[LogContext(domain: 'charge')]
class ChargeGateway implements ChargeGatewayInterface
{
    use ChargeAttemptMapperTrait;
    use TransactionMapperTrait;
    use DomainLoggerTrait;
    use SearchPaginationTrait;

    /**
     * How many attempts to request per page when listing them.
     */

    private SdkChargeAttemptsService $service;
    private SdkTransactionsService $transactionsService;

    /**
     * @param SdkProvider $sdkProvider The SDK provider.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
        $this->service = $this->sdkProvider->getService(SdkChargeAttemptsService::class);
        $this->transactionsService = $this->sdkProvider->getService(SdkTransactionsService::class);
    }

    /**
     * @inheritDoc
     */
    public function applyFlow(int $spaceId, int $transactionId): Transaction
    {
        $operation = 'postPaymentTransactionsIdChargeFlowApply';
        $context = ['spaceId' => $spaceId, 'transactionId' => $transactionId];

        $this->logger->debug('Calling charge operation.', ['operation' => $operation] + $context);

        try {
            // This API takes the transaction ID first and the space ID second.
            $response = $this->transactionsService->postPaymentTransactionsIdChargeFlowApply(
                $transactionId,
                $spaceId,
            );
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
     * @inheritDoc
     */
    public function findAllAttemptsByTransaction(int $spaceId, int $transactionId): array
    {
        $operation = 'getPaymentChargeAttemptsSearch';
        $context = ['spaceId' => $spaceId, 'transactionId' => $transactionId];

        // V2 Search: 'field:value' terms, space separated, combined conjunctively.
        // Filtered by transaction only: which attempt matters is the domain's call,
        // so no state term is applied here.
        $query = sprintf('charge.transaction.id:%d', $transactionId);

        $attempts = [];

        // This endpoint is paginated, so one call is not guaranteed to return every
        // attempt. Callers are promised the complete list, so page until it is.
        $sdkChargeAttempts = $this->paginateSearch(
            function (int $offset) use ($spaceId, $query, $operation, $context): object {
                $pageContext = ['offset' => $offset] + $context;
                $this->logger->debug('Calling charge operation.', ['operation' => $operation] + $pageContext);

                try {
                    $response = $this->service->getPaymentChargeAttemptsSearch(
                        $spaceId,
                        null,
                        SdkProvider::MAX_PAGE_SIZE,
                        $offset,
                        null,
                        $query,
                    );
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'Charge operation failed.',
                        ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e] + $pageContext,
                    );

                    throw SdkProvider::wrapException(
                        $e,
                        ChargeException::class,
                        $operation,
                        $pageContext,
                        'An error occurred while processing the charge.',
                    );
                }

                // This SDK answers some non-2xx replies with an error model instead of throwing.
                if (!$response instanceof SdkChargeAttemptSearchResponse) {
                    $errorContext = ['operation' => $operation, 'responseType' => get_debug_type($response)];

                    if ($response instanceof SdkRestApiErrorResponse) {
                        $errorContext['errorMessage'] = $response->getMessage();
                        $errorContext['errorCode'] = $response->getCode();
                    }

                    $this->logger->error(
                        'Charge operation returned an unexpected response.',
                        $errorContext + $context,
                    );

                    throw SdkProvider::unexpectedResponseException(
                        ChargeException::class,
                        $operation,
                        $context,
                        'An error occurred while processing the charge.',
                    );
                }

                return $response;
            },
        );

        foreach ($sdkChargeAttempts as $sdkChargeAttempt) {
            $this->validateChargeAttemptResponse($sdkChargeAttempt, $operation, $context);
            $attempts[] = $this->mapToChargeAttempt($sdkChargeAttempt);
        }

        if ($attempts === []) {
            // A transaction with no charge attempts is an ordinary outcome: it may
            // simply not have been charged yet. Logged at debug for that reason —
            // nothing happened that an operator needs told about.
            $this->logger->debug(
                'No charge attempts found for the transaction.',
                ['operation' => $operation] + $context,
            );
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
            $errorContext = ['operation' => $operation, 'responseType' => get_debug_type($result)];

            if ($result instanceof SdkRestApiErrorResponse) {
                $errorContext['errorMessage'] = $result->getMessage();
                $errorContext['errorCode'] = $result->getCode();
            }

            $this->logger->error(
                'Charge operation returned an unexpected response.',
                $errorContext + $context,
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
     * This SDK answers some non-2xx replies with an error model instead of throwing,
     * so a response that is not a transaction is the failure path, not a surprise.
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
            $errorContext = ['operation' => $operation, 'responseType' => get_debug_type($result)];

            if ($result instanceof SdkRestApiErrorResponse) {
                $errorContext['errorMessage'] = $result->getMessage();
                $errorContext['errorCode'] = $result->getCode();
            }

            $this->logger->error(
                'Charge operation returned an unexpected response.',
                $errorContext + $context,
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
