<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Token;

use WeArePlanet\PluginCore\Token\Exception\MissingTokenException;
use WeArePlanet\PluginCore\Token\Exception\TokenException;

/**
 * Access to stored payment credentials and their versions.
 *
 * Implementations translate the API's representation into the {@see Token} and
 * {@see TokenVersion} domain entities, so consumers never handle SDK models and
 * see no difference between API versions.
 *
 * ## Parameter order
 *
 * Every method takes the space first, then the entity ID. Both arguments are
 * integers, so a transposed call would be accepted by PHP and by static analysis
 * and would fail only at runtime against the wrong entity. Implementations are
 * responsible for re-ordering the arguments to whatever their SDK expects;
 * callers always use this one order.
 *
 * ## Missing entities
 *
 * The version lookups return null when the entity does not exist, because asking
 * for a version a token does not have is an ordinary question rather than an
 * error. Failures of the lookup itself — an unreachable or rejecting API — still
 * throw.
 */
interface TokenGatewayInterface
{
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
    public function createToken(int $spaceId, int $transactionId): Token;

    /**
     * Deletes a token.
     *
     * Deletion is permanent and makes the stored credentials unusable for any future
     * charge, including scheduled recurring ones. It is the operation to call when a
     * customer withdraws consent or removes a saved payment method.
     *
     * @param int $spaceId The ID of the space the token belongs to.
     * @param int $tokenId The ID of the token to delete.
     * @return void
     * @throws TokenException If the token cannot be deleted, including when no token
     *         with that ID exists.
     */
    public function deleteToken(int $spaceId, int $tokenId): void;

    /**
     * Returns the version of a token that is currently in force.
     *
     * @param int $spaceId The ID of the space the token belongs to.
     * @param int $tokenId The ID of the token to read the active version of.
     * @return TokenVersion|null The active version, or null when the token has none —
     *         it may have been deactivated, or never activated at all.
     * @throws TokenException If the lookup fails at the API or transport level.
     */
    public function getActiveTokenVersion(int $spaceId, int $tokenId): ?TokenVersion;

    /**
     * Reads one version of a token by its own ID.
     *
     * @param int $spaceId The ID of the space the token version belongs to.
     * @param int $tokenVersionId The ID of the token version to read.
     * @return TokenVersion|null The token version, or null when no version with that
     *         ID exists in the space.
     * @throws TokenException If the lookup fails at the API or transport level.
     */
    public function getTokenVersion(int $spaceId, int $tokenVersionId): ?TokenVersion;
}
