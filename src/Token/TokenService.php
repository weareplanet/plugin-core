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
        } catch (ApiException $e) {
            $this->logger->error("Failed to create token for transaction.", [
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
                'exception' => $e,
            ]);
            throw new TokenException(
                "Failed to create token for Transaction $transactionId: " . $e->getMessage(),
                new LocalizedString('Token creation failed. Please try again or contact support.'),
                $e,
            );
        } catch (\Exception $e) {
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
