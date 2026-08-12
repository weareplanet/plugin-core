<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\ManualTask;

use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\ManualTask\Exception\ManualTaskException;

/**
 * Domain-facing service for reading manual tasks.
 *
 * This is the entry point consumers use; it delegates to the configured
 * {@see ManualTaskGatewayInterface}, which owns the API interaction, its logging and
 * its failure handling. The service deliberately adds no logging or exception
 * wrapping of its own: doing so would record every failure twice and re-wrap
 * exceptions that are already domain exceptions.
 */
#[LogContext(domain: 'manual_task')]
class ManualTaskService
{
    use DomainLoggerTrait;

    /**
     * @param ManualTaskGatewayInterface $manualTaskGateway The gateway to delegate to.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private ManualTaskGatewayInterface $manualTaskGateway,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * Counts the manual tasks in the given space that are in the given state.
     *
     * {@see State::OPEN} is the state worth surfacing to a merchant: it is work that
     * still has to be done before the transactions behind it can proceed.
     *
     * @param int $spaceId The space to count manual tasks in.
     * @param State $state The manual task state to filter by.
     * @return int The number of matching manual tasks.
     * @throws ManualTaskException If the count cannot be read at the API or transport level.
     */
    public function countByState(int $spaceId, State $state): int
    {
        return $this->manualTaskGateway->countByState($spaceId, $state);
    }

    /**
     * Counts every manual task in the given space, whatever its state.
     *
     * Useful as a sanity check when a per-state count comes back unexpectedly
     * empty: a zero here as well points at the space rather than the state filter.
     *
     * @param int $spaceId The space to count manual tasks in.
     * @return int The total number of manual tasks in the space.
     * @throws ManualTaskException If the count cannot be read at the API or transport level.
     */
    public function countAll(int $spaceId): int
    {
        return $this->manualTaskGateway->countAll($spaceId);
    }
}
