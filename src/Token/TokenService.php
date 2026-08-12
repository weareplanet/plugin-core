<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Token;

use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Token\Exception\MissingTokenException;
use WeArePlanet\PluginCore\Token\Exception\TokenException;

/**
 * Domain-facing service for stored payment credentials and their versions.
 *
 * This is the entry point consumers use; it delegates to the configured
 * {@see TokenGatewayInterface}, which owns the API interaction, its logging and
 * its failure handling. The service deliberately adds no logging or exception
 * wrapping of its own: doing so would record every failure twice and re-wrap
 * exceptions that are already domain exceptions.
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
     * Creates a token for the given transaction.
     *
     * @param int $spaceId The ID of the space the transaction belongs to.
     * @param int $transactionId The ID of the transaction to create the token from.
     * @return Token The token.
     * @throws MissingTokenException If the transaction has no token, which happens when
     *         it was not created with a tokenization mode that produces one.
     * @throws TokenException If the operation fails at the API or transport level.
     */
    public function createTokenForTransaction(int $spaceId, int $transactionId): Token
    {
        return $this->tokenGateway->createToken($spaceId, $transactionId);
    }

    /**
     * Deletes a token.
     *
     * Deletion is permanent and makes the stored credentials unusable for any future
     * charge, including scheduled recurring ones.
     *
     * @param int $spaceId The ID of the space the token belongs to.
     * @param int $tokenId The ID of the token to delete.
     * @return void
     * @throws TokenException If the token cannot be deleted.
     */
    public function deleteToken(int $spaceId, int $tokenId): void
    {
        $this->tokenGateway->deleteToken($spaceId, $tokenId);
    }

    /**
     * Returns the version of a token that is currently in force.
     *
     * @param int $spaceId The ID of the space the token belongs to.
     * @param int $tokenId The ID of the token to read the active version of.
     * @return TokenVersion|null The active version, or null when the token has none.
     * @throws TokenException If the lookup fails at the API or transport level.
     */
    public function getActiveTokenVersion(int $spaceId, int $tokenId): ?TokenVersion
    {
        return $this->tokenGateway->getActiveTokenVersion($spaceId, $tokenId);
    }

    /**
     * Reads one version of a token by its own ID.
     *
     * @param int $spaceId The ID of the space the token version belongs to.
     * @param int $tokenVersionId The ID of the token version to read.
     * @return TokenVersion|null The token version, or null when no version with that
     *         ID exists in the space.
     * @throws TokenException If the lookup fails at the API or transport level.
     */
    public function getTokenVersion(int $spaceId, int $tokenVersionId): ?TokenVersion
    {
        return $this->tokenGateway->getTokenVersion($spaceId, $tokenVersionId);
    }
}
