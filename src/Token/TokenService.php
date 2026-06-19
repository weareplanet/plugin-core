<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Token;

use Psr\Log\LoggerInterface;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Token\Exception\TokenException;
use WeArePlanet\Sdk\ApiException;

/**
 * Service for managing tokens.
 */
class TokenService
{
    public function __construct(
        private TokenGatewayInterface $tokenGateway,
        private LoggerInterface $logger,
    ) {
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
            $this->logger->debug("Attempting to create a token for Transaction $transactionId in Space $spaceId.");
            $token = $this->tokenGateway->createToken($spaceId, $transactionId);
            $this->logger->debug("Successfully created Token {$token->id} for Transaction $transactionId.");
            return $token;
        } catch (ApiException $e) {
            $this->logger->error("Failed to create token for Transaction $transactionId: " . $e->getMessage());
            throw new TokenException(
                "Failed to create token for Transaction $transactionId: " . $e->getMessage(),
                new LocalizedString($e->getMessage()),
                0,
                $e,
            );
        } catch (\Exception $e) {
            $this->logger->error("Unexpected error creating token for Transaction $transactionId: " . $e->getMessage());
            throw new TokenException(
                "Unexpected error creating token for Transaction $transactionId: " . $e->getMessage(),
                new LocalizedString($e->getMessage()),
                0,
                $e,
            );
        }
    }
}
