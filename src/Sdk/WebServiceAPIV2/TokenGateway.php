<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\TokenMapperTrait;
use WeArePlanet\PluginCore\Token\Exception\MissingTokenException;
use WeArePlanet\PluginCore\Token\Exception\TokenException;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\TokenGatewayInterface;
use WeArePlanet\PluginCore\Token\TokenVersion;
use WeArePlanet\Sdk\ApiException;
use WeArePlanet\Sdk\Model\PaymentConnector as SdkPaymentConnector;
use WeArePlanet\Sdk\Model\RestApiErrorResponse as SdkRestApiErrorResponse;
use WeArePlanet\Sdk\Model\Token as SdkToken;
use WeArePlanet\Sdk\Model\TokenVersion as SdkTokenVersion;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;
use WeArePlanet\Sdk\Service\TokensService as SdkTokensService;
use WeArePlanet\Sdk\Service\TokenVersionsService as SdkTokenVersionsService;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;

/**
 * SDK implementation of the TokenGatewayInterface for API V2.
 *
 * Several things differ from WebServiceAPIV1 and are absorbed here so consumers
 * never see them:
 *
 * - This SDK takes `($entityId, $spaceId)` — the reverse of the domain interface's
 *   order. Both are integers, so the transposition is invisible to PHP and to
 *   static analysis; it is done in exactly one place per operation and asserted by
 *   the gateway's tests.
 * - Operations report some failures by returning an error model rather than
 *   throwing, so {@see call()} resolves that union before anything else sees it.
 * - The active-version lookup lives on the tokens service while reading a version
 *   by ID lives on the token versions service, so this gateway holds both.
 * - Related entities are only present in a payload when requested with `expand`,
 *   so the lookups ask for the owning token explicitly.
 *
 * Converting SDK models into domain entities is {@see TokenMapperTrait}'s job —
 * including the payload-shape differences behind those entities; this class owns
 * the calls, their observability and their failure handling.
 */
#[LogContext(domain: 'transaction', subdomain: 'recurring')]
class TokenGateway implements TokenGatewayInterface
{
    use DomainLoggerTrait;
    use TokenMapperTrait;

    private SdkTokensService $tokensService;
    private SdkTokenVersionsService $tokenVersionsService;
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
        $this->tokensService = $this->sdkProvider->getService(SdkTokensService::class);
        $this->tokenVersionsService = $this->sdkProvider->getService(SdkTokenVersionsService::class);
        $this->transactionsService = $this->sdkProvider->getService(SdkTransactionsService::class);
    }

    /**
     * @inheritDoc
     */
    public function createToken(int $spaceId, int $transactionId): Token
    {
        $operation = 'getPaymentTransactionsId';
        $context = ['spaceId' => $spaceId, 'transactionId' => $transactionId];

        $this->logger->debug('Calling token operation.', ['operation' => $operation] + $context);

        try {
            // Argument order reversed for this SDK: transaction first, space second.
            $transaction = $this->transactionsService->getPaymentTransactionsId($transactionId, $spaceId, ['token']);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Token operation failed.',
                ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e] + $context,
            );

            throw SdkProvider::wrapException(
                $e,
                TokenException::class,
                $operation,
                $context,
                'An error occurred while processing the payment token.',
            );
        }

        $sdkToken = $transaction instanceof SdkTransaction ? $transaction->getToken() : null;

        if ($sdkToken === null) {
            // The transaction carries no token, which means it was not created with a
            // tokenization mode that produces one. That is a caller mistake rather than
            // an API failure, so it gets its own exception type.
            $this->logger->error(
                'Token creation failed: the transaction has no associated token.',
                ['operation' => $operation] + $context,
            );
            throw new MissingTokenException(
                "Transaction {$transactionId} in Space {$spaceId} has no associated token.",
                new LocalizedString('The transaction has no associated token.'),
            );
        }

        $this->validateTokenResponse($sdkToken, $operation, $context);

        return $this->mapToToken($sdkToken, $spaceId);
    }

    /**
     * @inheritDoc
     */
    public function deleteToken(int $spaceId, int $tokenId): void
    {
        $operation = 'deletePaymentTokensId';
        $context = ['spaceId' => $spaceId, 'tokenId' => $tokenId];

        $this->logger->debug('Calling token operation.', ['operation' => $operation] + $context);

        // Deletion reports nothing on success, so there is no response to interpret:
        // this SDK declares it void and signals any failure by throwing.
        try {
            // Argument order reversed for this SDK: token first, space second.
            $this->tokensService->deletePaymentTokensId($tokenId, $spaceId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Token operation failed.',
                ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e] + $context,
            );

            throw SdkProvider::wrapException(
                $e,
                TokenException::class,
                $operation,
                $context,
                'An error occurred while processing the payment token.',
            );
        }

        $this->logger->info('Token operation succeeded.', ['operation' => $operation] + $context);
    }

    /**
     * Validates that a raw SDK response is a token.
     *
     * @param mixed $result The raw SDK response.
     * @param string $operation The SDK operation name, for log context.
     * @param array<string, mixed> $context Identifying context for the log records.
     * @return void
     * @throws TokenException If the response was not a token.
     *
     * @phpstan-assert SdkToken $result
     */
    private function validateTokenResponse(mixed $result, string $operation, array $context): void
    {
        if (!$result instanceof SdkToken) {
            $errorContext = ['operation' => $operation, 'responseType' => get_debug_type($result)];

            if ($result instanceof SdkRestApiErrorResponse) {
                $errorContext['errorMessage'] = $result->getMessage();
                $errorContext['errorCode'] = $result->getCode();
            }

            $this->logger->error(
                'Token operation returned an unexpected response.',
                $errorContext + $context,
            );

            throw SdkProvider::unexpectedResponseException(
                TokenException::class,
                $operation,
                $context,
                'An error occurred while processing the payment token.',
            );
        }
    }

    /**
     * Validates that a raw SDK response is a token version carrying its owning token.
     *
     * The owning token is needed because the domain entity holds it; a payload without
     * one is rejected rather than mapped with an invented placeholder.
     *
     * @param mixed $result The raw SDK response.
     * @param string $operation The SDK operation name, for log context.
     * @param array<string, mixed> $context Identifying context for the log records.
     * @return void
     * @throws TokenException If the response was not a token version, or carried no
     *         owning token.
     *
     * @phpstan-assert SdkTokenVersion $result
     */
    private function validateTokenVersionResponse(mixed $result, string $operation, array $context): void
    {
        if (!$result instanceof SdkTokenVersion) {
            $errorContext = ['operation' => $operation, 'responseType' => get_debug_type($result)];

            if ($result instanceof SdkRestApiErrorResponse) {
                $errorContext['errorMessage'] = $result->getMessage();
                $errorContext['errorCode'] = $result->getCode();
            }

            $this->logger->error(
                'Token operation returned an unexpected response.',
                $errorContext + $context,
            );

            throw SdkProvider::unexpectedResponseException(
                TokenException::class,
                $operation,
                $context,
                'An error occurred while processing the payment token.',
            );
        }

        if (!$result->getToken() instanceof SdkToken) {
            // $result is already a validated SdkTokenVersion by this point (the guard
            // above only lets that type through), so unlike the guard above, it can
            // never be a SdkRestApiErrorResponse here — no enrichment to extract.
            $this->logger->error(
                'Token operation returned an unexpected response.',
                ['operation' => $operation, 'responseType' => get_debug_type($result)] + $context,
            );

            throw SdkProvider::unexpectedResponseException(
                TokenException::class,
                $operation,
                $context,
                'An error occurred while processing the payment token.',
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getActiveTokenVersion(int $spaceId, int $tokenId): ?TokenVersion
    {
        $operation = 'getPaymentTokensIdActiveVersion';
        $context = ['spaceId' => $spaceId, 'tokenId' => $tokenId];

        $this->logger->debug('Calling token operation.', ['operation' => $operation] + $context);

        try {
            // Argument order reversed for this SDK: token first, space second. Related
            // entities are only present in the payload when requested via expand.
            $result = $this->tokensService->getPaymentTokensIdActiveVersion($tokenId, $spaceId, ['token']);
        } catch (\Throwable $e) {
            if ($e instanceof ApiException && $e->getCode() === 404) {
                // Absence is an ordinary answer for this lookup, not a failure.
                $this->logger->info(
                    'Token operation found no matching entity.',
                    ['operation' => $operation] + $context,
                );

                return null;
            }

            $this->logger->error(
                'Token operation failed.',
                ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e] + $context,
            );

            throw SdkProvider::wrapException(
                $e,
                TokenException::class,
                $operation,
                $context,
                'An error occurred while processing the payment token.',
            );
        }

        if ($result === null) {
            return null;
        }

        $this->validateTokenVersionResponse($result, $operation, $context);

        return $this->mapToTokenVersion($result, $spaceId);
    }

    /**
     * @inheritDoc
     */
    public function getTokenVersion(int $spaceId, int $tokenVersionId): ?TokenVersion
    {
        $operation = 'getPaymentTokenVersionsId';
        $context = ['spaceId' => $spaceId, 'tokenVersionId' => $tokenVersionId];

        $this->logger->debug('Calling token operation.', ['operation' => $operation] + $context);

        try {
            // Argument order reversed for this SDK: version first, space second. Related
            // entities are only present in the payload when requested via expand.
            $result = $this->tokenVersionsService->getPaymentTokenVersionsId(
                $tokenVersionId,
                $spaceId,
                ['token'],
            );
        } catch (\Throwable $e) {
            if ($e instanceof ApiException && $e->getCode() === 404) {
                // Absence is an ordinary answer for this lookup, not a failure.
                $this->logger->info(
                    'Token operation found no matching entity.',
                    ['operation' => $operation] + $context,
                );

                return null;
            }

            $this->logger->error(
                'Token operation failed.',
                ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e] + $context,
            );

            throw SdkProvider::wrapException(
                $e,
                TokenException::class,
                $operation,
                $context,
                'An error occurred while processing the payment token.',
            );
        }

        if ($result === null) {
            return null;
        }

        $this->validateTokenVersionResponse($result, $operation, $context);

        return $this->mapToTokenVersion($result, $spaceId);
    }

}
