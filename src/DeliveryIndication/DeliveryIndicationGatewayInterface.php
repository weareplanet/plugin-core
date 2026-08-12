<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\DeliveryIndication;

use WeArePlanet\PluginCore\DeliveryIndication\Exception\DeliveryIndicationException;

/**
 * Read and decision access to delivery indications.
 *
 * A delivery indication asks the merchant whether a captured payment is suitable
 * for delivery. Implementations translate the API's representation into the
 * {@see DeliveryIndication} domain entity, so consumers never handle SDK models
 * and see no difference between API versions.
 *
 * ## Parameter order
 *
 * Every method takes `(int $spaceId, int $indicationId)`, in that order, without
 * exception. Both arguments are integers, so a transposed call would be accepted
 * by PHP and by static analysis, and would fail only at runtime against the wrong
 * entity. Implementations are responsible for re-ordering the arguments to
 * whatever their SDK expects; callers always use this one order.
 */
interface DeliveryIndicationGatewayInterface
{
    /**
     * Finds the delivery indication raised for a transaction.
     *
     * Intended for consumers that hold a transaction — an order record, typically —
     * but not the ID of the indication itself, which the WeArePlanet Portal assigns.
     *
     * A transaction has at most one delivery indication. Having none is an ordinary
     * outcome rather than an error: the payment may not be captured yet, or may not
     * have been selected for review at all. That case is reported as null, so callers
     * do not have to catch an exception to handle the common path.
     *
     * @param int $spaceId The ID of the space the transaction belongs to.
     * @param int $transactionId The ID of the transaction to find the indication for.
     * @return DeliveryIndication|null The delivery indication, or null when the
     *         transaction has none.
     * @throws DeliveryIndicationException If the lookup itself fails, e.g. because the
     *         API is unreachable or rejects the request. Not thrown for an empty result.
     */
    public function findByTransaction(int $spaceId, int $transactionId): ?DeliveryIndication;

    /**
     * Reads a delivery indication.
     *
     * @param int $spaceId The ID of the space the delivery indication belongs to.
     * @param int $indicationId The ID of the delivery indication to read.
     * @return DeliveryIndication The delivery indication.
     * @throws DeliveryIndicationException If the delivery indication cannot be read,
     *         including when no indication with that ID exists in the space. Exposes
     *         `isRetryable()` like every other PluginCore exception.
     */
    public function get(int $spaceId, int $indicationId): DeliveryIndication;

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
    public function markAsNotSuitable(int $spaceId, int $indicationId): DeliveryIndication;

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
    public function markAsSuitable(int $spaceId, int $indicationId): DeliveryIndication;
}
