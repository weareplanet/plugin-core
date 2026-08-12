<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Charge;

use WeArePlanet\PluginCore\Charge\Attempt\ChargeAttempt;
use WeArePlanet\PluginCore\Charge\Attempt\Label;
use WeArePlanet\PluginCore\Charge\Exception\ChargeException;
use WeArePlanet\PluginCore\Transaction\Transaction;

/**
 * Charging a transaction, and reading how the charge went.
 *
 * The two halves belong together: applying a charge flow is what produces a
 * charge attempt, and a consumer that does one usually goes on to do the other.
 * Implementations translate the API's representations into domain entities, so
 * consumers never handle SDK models.
 *
 * ## Portability note
 *
 * {@see Label::$groupId} and {@see Label::$content} are populated identically by
 * every implementation. {@see Label::$groupName} is populated only where the API
 * returns the descriptor group inline with the charge attempt — the
 * `WebServiceAPIV1` implementation leaves it null, since that API reports the
 * group as a bare ID. Code that must behave the same on both APIs should filter
 * on `groupId` and treat `groupName` as an optional display extra.
 */
interface ChargeGatewayInterface
{
    /**
     * Applies the charge flow to the given transaction, charging it.
     *
     * This is a command, not a query: it asks the WeArePlanet Portal to process the transaction
     * through its configured charge flow. The flow runs asynchronously, so the
     * returned transaction reflects the state at the moment the flow was applied,
     * not the final outcome of the charge — read the transaction again, or wait for
     * the corresponding webhook, to learn how it ended.
     *
     * @param int $spaceId The ID of the space the transaction belongs to.
     * @param int $transactionId The ID of the transaction to charge.
     * @return Transaction The transaction as reported once the flow was applied.
     * @throws ChargeException If the flow cannot be applied, e.g. because the API is
     *         unreachable, rejects the request, or the transaction is in a state that
     *         does not permit charging. Exposes `isRetryable()` like every other
     *         PluginCore exception.
     */
    public function applyFlow(int $spaceId, int $transactionId): Transaction;

    /**
     * Returns all charge attempts associated with the given transaction.
     *
     * A transaction can be charged more than once — a retry after a decline, for
     * example — so this reports every run, in the order the API returned them,
     * whatever state each is in. A transaction that has never been charged has no
     * attempts, which is a normal outcome and reported as an empty list rather than
     * as an error.
     *
     * Implementations do not filter by state: deciding which attempt matters is the
     * domain's job, so that rule lives on {@see ChargeAttempt} rather than being
     * restated against each API's own state constants.
     *
     * @param int $spaceId The ID of the space the transaction belongs to.
     * @param int $transactionId The ID of the transaction to read attempts for.
     * @return list<ChargeAttempt> The charge attempts, ordered as returned by the API.
     * @throws ChargeException If the attempts cannot be retrieved, e.g. because the
     *         API is unreachable or rejects the request. Exposes `isRetryable()` like
     *         every other PluginCore exception.
     */
    public function findAllAttemptsByTransaction(int $spaceId, int $transactionId): array;
}
