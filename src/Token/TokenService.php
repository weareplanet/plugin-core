<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Token;

use Psr\Log\LoggerInterface;
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
     * @return Token|null The created token or null if creation failed.
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
        } catch (\Exception $e) {
            $this->logger->error("Unexpected error creating token for Transaction $transactionId: " . $e->getMessage());
        }

        return null;
    }
}
