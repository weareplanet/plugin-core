<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;

/**
 * Domain-facing service for reading transaction comments.
 *
 * This is the entry point consumers use; it delegates to the configured
 * {@see TransactionCommentGatewayInterface}, which owns the API interaction, its
 * logging and its failure handling. The service deliberately adds no logging or
 * exception wrapping of its own: doing so would record every failure twice and
 * re-wrap exceptions that are already domain exceptions.
 */
#[LogContext(domain: 'transaction')]
class TransactionCommentService
{
    use DomainLoggerTrait;

    /**
     * @param TransactionCommentGatewayInterface $transactionCommentGateway The gateway to delegate to.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private TransactionCommentGatewayInterface $transactionCommentGateway,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * Gets comments for a transaction.
     *
     * A transaction with no comments is an ordinary outcome, reported as an empty
     * collection rather than as an exception.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return TransactionCommentCollection The list of comments.
     */
    public function getComments(int $spaceId, int $transactionId): TransactionCommentCollection
    {
        return $this->transactionCommentGateway->getComments($spaceId, $transactionId);
    }
}
