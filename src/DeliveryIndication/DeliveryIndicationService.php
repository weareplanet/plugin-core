<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\DeliveryIndication;

use WeArePlanet\PluginCore\DeliveryIndication\Exception\DeliveryIndicationException;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;

/**
 * Domain-facing service for reading and deciding delivery indications.
 *
 * This is the entry point consumers use; it delegates to the configured
 * {@see DeliveryIndicationGatewayInterface}, which owns the API interaction, its
 * logging and its failure handling. The service deliberately adds no logging or
 * exception wrapping of its own: doing so would record every failure twice and
 * re-wrap exceptions that are already domain exceptions.
 */
#[LogContext(domain: 'delivery_indication')]
class DeliveryIndicationService
{
    use DomainLoggerTrait;

    /**
     * @param DeliveryIndicationGatewayInterface $deliveryIndicationGateway The gateway to delegate to.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private DeliveryIndicationGatewayInterface $deliveryIndicationGateway,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * Finds the delivery indication raised for a transaction.
     *
     * Intended for consumers that hold a transaction — an order record, typically —
     * but not the ID of the indication itself, which the WeArePlanet Portal assigns.
     *
     * @param int $spaceId The ID of the space the transaction belongs to.
     * @param int $transactionId The ID of the transaction to find the indication for.
     * @return DeliveryIndication|null The delivery indication, or null when the
     *         transaction has none.
     * @throws DeliveryIndicationException If the lookup itself fails, e.g. because the
     *         API is unreachable or rejects the request. Not thrown for an empty result.
     */
    public function findByTransaction(int $spaceId, int $transactionId): ?DeliveryIndication
    {
        return $this->deliveryIndicationGateway->findByTransaction($spaceId, $transactionId);
    }

    /**
     * Reads a delivery indication.
     *
     * @param int $spaceId The ID of the space the delivery indication belongs to.
     * @param int $indicationId The ID of the delivery indication to read.
     * @return DeliveryIndication The delivery indication.
     * @throws DeliveryIndicationException If the delivery indication cannot be read,
     *         including when no indication with that ID exists in the space.
     */
    public function get(int $spaceId, int $indicationId): DeliveryIndication
    {
        return $this->deliveryIndicationGateway->get($spaceId, $indicationId);
    }

    /**
     * Marks a delivery indication as not suitable for delivery.
     *
     * Only an undecided indication can be marked; the API rejects a second decision.
     * Check {@see DeliveryIndication::isDecisionPending()} first when the caller
     * cannot be sure.
     *
     * @param int $spaceId The ID of the space the delivery indication belongs to.
     * @param int $indicationId The ID of the delivery indication to mark.
     * @return DeliveryIndication The delivery indication as it stands after the decision.
     * @throws DeliveryIndicationException If the decision cannot be recorded, e.g.
     *         because the indication has already been decided or the API is unreachable.
     */
    public function markAsNotSuitable(int $spaceId, int $indicationId): DeliveryIndication
    {
        return $this->deliveryIndicationGateway->markAsNotSuitable($spaceId, $indicationId);
    }

    /**
     * Marks a delivery indication as suitable for delivery.
     *
     * Only an undecided indication can be marked; the API rejects a second decision.
     * Check {@see DeliveryIndication::isDecisionPending()} first when the caller
     * cannot be sure.
     *
     * @param int $spaceId The ID of the space the delivery indication belongs to.
     * @param int $indicationId The ID of the delivery indication to mark.
     * @return DeliveryIndication The delivery indication as it stands after the decision.
     * @throws DeliveryIndicationException If the decision cannot be recorded, e.g.
     *         because the indication has already been decided or the API is unreachable.
     */
    public function markAsSuitable(int $spaceId, int $indicationId): DeliveryIndication
    {
        return $this->deliveryIndicationGateway->markAsSuitable($spaceId, $indicationId);
    }
}
