<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Token;

use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Token\Exception\TokenException;

/**
 * Service for managing tokens.
 */
#[LogContext(domain: 'transaction', subdomain: 'recurring')]
class TokenService
{
    use DomainLoggerTrait;
    public function __construct(
        private TokenGatewayInterface $tokenGateway,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * Attempts to create a token for a given transaction.
     *
     * @param int $spaceId
     * @param int $transactionId
     * @return Token The created token.
     * @throws TokenException If token creation fails at the API or transport level.
     */
    public function createTokenForTransaction(int $spaceId, int $transactionId): ?Token
    {
        try {
            $this->logger->debug("Attempting to create a token for transaction.", [
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
            ]);
            $token = $this->tokenGateway->createToken($spaceId, $transactionId);
            $this->logger->debug("Successfully created token for transaction.", [
                'tokenId' => $token->id,
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
            ]);
            return $token;
        } catch (TokenException $e) {
            // Already a domain exception from the gateway; log and pass through.
            $this->logger->error("Failed to create token for transaction.", [
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
                'exception' => $e,
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error("Unexpected error creating token for transaction.", [
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
                'exception' => $e,
            ]);
            throw new TokenException(
                "Unexpected error creating token for Transaction $transactionId: " . $e->getMessage(),
                new LocalizedString('An unexpected error occurred during token creation. Please contact support.'),
                $e,
            );
        }
    }
}
