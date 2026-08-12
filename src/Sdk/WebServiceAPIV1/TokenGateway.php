<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV1;

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
use WeArePlanet\Sdk\Model\Token as SdkToken;
use WeArePlanet\Sdk\Model\TokenVersion as SdkTokenVersion;
use WeArePlanet\Sdk\Service\TokenService as SdkTokenService;
use WeArePlanet\Sdk\Service\TokenVersionService as SdkTokenVersionService;

/**
 * SDK implementation of the TokenGatewayInterface for API V1.
 *
 * This SDK takes `($spaceId, $entityId)`, which matches the domain interface's
 * order, so the arguments are passed straight through, and it exposes token
 * versions through a dedicated service.
 *
 * Converting SDK models into domain entities is {@see TokenMapperTrait}'s job;
 * this class owns the calls, their observability and their failure handling.
 */
#[LogContext(domain: 'transaction', subdomain: 'recurring')]
class TokenGateway implements TokenGatewayInterface
{
    use DomainLoggerTrait;
    use TokenMapperTrait;

    private SdkTokenService $tokenService;
    private SdkTokenVersionService $tokenVersionService;

    /**
     * @param SdkProvider $sdkProvider The SDK provider.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
        $this->tokenService = $this->sdkProvider->getService(SdkTokenService::class);
        $this->tokenVersionService = $this->sdkProvider->getService(SdkTokenVersionService::class);
    }

    /**
     * @inheritDoc
     */
    public function createToken(int $spaceId, int $transactionId): Token
    {
        $operation = 'createToken';
        $context = ['spaceId' => $spaceId, 'transactionId' => $transactionId];

        $this->logger->debug('Calling token operation.', ['operation' => $operation] + $context);

        try {
            $result = $this->tokenService->createToken($spaceId, $transactionId);
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

        if ($result === null) {
            // The transaction carries no token, which means it was not created with a
            // tokenization mode that produces one. This is a caller mistake rather than
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

        $this->validateTokenResponse($result, $operation, $context);

        return $this->mapToToken($result, $spaceId);
    }

    /**
     * @inheritDoc
     */
    public function deleteToken(int $spaceId, int $tokenId): void
    {
        $operation = 'delete';
        $context = ['spaceId' => $spaceId, 'tokenId' => $tokenId];

        $this->logger->debug('Calling token operation.', ['operation' => $operation] + $context);

        // Deletion reports nothing on success, so there is no response to interpret.
        try {
            $this->tokenService->delete($spaceId, $tokenId);
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
     * @inheritDoc
     */
    public function getActiveTokenVersion(int $spaceId, int $tokenId): ?TokenVersion
    {
        $operation = 'activeVersion';
        $context = ['spaceId' => $spaceId, 'tokenId' => $tokenId];

        $this->logger->debug('Calling token operation.', ['operation' => $operation] + $context);

        try {
            $result = $this->tokenVersionService->activeVersion($spaceId, $tokenId);
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
        $operation = 'read';
        $context = ['spaceId' => $spaceId, 'tokenVersionId' => $tokenVersionId];

        $this->logger->debug('Calling token operation.', ['operation' => $operation] + $context);

        try {
            $result = $this->tokenVersionService->read($spaceId, $tokenVersionId);
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

        if (!$result->getToken() instanceof SdkToken) {
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
}
