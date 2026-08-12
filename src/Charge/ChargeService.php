<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Charge;

use WeArePlanet\PluginCore\Charge\Attempt\ChargeAttempt;
use WeArePlanet\PluginCore\Charge\Exception\ChargeException;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Transaction\Transaction;

/**
 * Domain-facing service for charging a transaction and reading the result.
 *
 * This is the entry point consumers use; it delegates to the configured
 * {@see ChargeGatewayInterface}, which owns the API interaction, its logging and
 * its failure handling. The service deliberately adds no logging or exception
 * wrapping of its own: doing so would record every failure twice and re-wrap
 * exceptions that are already domain exceptions.
 */
#[LogContext(domain: 'charge')]
class ChargeService
{
    use DomainLoggerTrait;

    /**
     * @param ChargeGatewayInterface $chargeGateway The gateway to delegate to.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private ChargeGatewayInterface $chargeGateway,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * Applies the charge flow to the given transaction, charging it.
     *
     * The flow runs asynchronously, so the returned transaction reflects the state
     * at the moment the flow was applied, not the final outcome of the charge.
     *
     * @param int $spaceId The ID of the space the transaction belongs to.
     * @param int $transactionId The ID of the transaction to charge.
     * @return Transaction The transaction as reported once the flow was applied.
     * @throws ChargeException If the flow cannot be applied at the API or transport
     *         level, or the transaction is in a state that does not permit charging.
     */
    public function applyFlow(int $spaceId, int $transactionId): Transaction
    {
        return $this->chargeGateway->applyFlow($spaceId, $transactionId);
    }

    /**
     * Returns every charge attempt of the given transaction.
     *
     * A transaction can be charged more than once — a retry after a decline, for
     * example — so this reports every run, whatever state each is in, in the order
     * the API returned them. A transaction that has never been charged has no
     * attempts, which is an ordinary outcome reported as an empty list.
     *
     * @param int $spaceId The ID of the space the transaction belongs to.
     * @param int $transactionId The ID of the transaction to read attempts for.
     * @return list<ChargeAttempt> The charge attempts, ordered as returned by the API.
     * @throws ChargeException If the lookup fails at the API or transport level.
     */
    public function findAllAttemptsByTransaction(int $spaceId, int $transactionId): array
    {
        return $this->chargeGateway->findAllAttemptsByTransaction($spaceId, $transactionId);
    }

    /**
     * Returns the successful charge attempt of the given transaction.
     *
     * This is a filter over {@see findAllAttemptsByTransaction()}: it reads every
     * attempt of the transaction and returns the first one that
     * {@see ChargeAttempt::isSuccessful()} reports as successful. A transaction has at
     * most one successful attempt, so the first match is the answer.
     *
     * Filtering happens here rather than in the query so that what counts as success
     * is decided in one place — on the entity — instead of being restated against each
     * API version's own state constants.
     *
     * A transaction that has not been charged, or whose charge did not succeed, has
     * no successful attempt. That is an ordinary outcome and reported as null.
     *
     * @param int $spaceId The ID of the space the transaction belongs to.
     * @param int $transactionId The ID of the transaction to read the charge attempt of.
     * @return ChargeAttempt|null The successful charge attempt, or null when the
     *         transaction has none.
     * @throws ChargeException If the lookup fails at the API or transport level.
     */
    public function findSuccessfulAttemptByTransaction(int $spaceId, int $transactionId): ?ChargeAttempt
    {
        foreach ($this->findAllAttemptsByTransaction($spaceId, $transactionId) as $attempt) {
            if ($attempt->isSuccessful()) {
                return $attempt;
            }
        }

        return null;
    }
}
