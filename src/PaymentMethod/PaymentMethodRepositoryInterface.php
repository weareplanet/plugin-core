<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\PaymentMethod;

/**
 * Contract for shop plugins to handle the physical persistence of Payment Methods.
 *
 * The diffing and synchronization orchestration is handled by PaymentMethodService,
 * which calls these granular methods. Plugin developers only need to implement
 * basic database operations — the "what to create, update, or deactivate" decision
 * is made by PluginCore.
 */
interface PaymentMethodRepositoryInterface
{
    /**
     * Inserts a new payment method into the shop's local database.
     *
     * Called by the synchronization algorithm when an API method has no
     * matching local record.
     *
     * @param PaymentMethod $method The payment method to persist.
     * @param int $spaceId The space context for this operation.
     */
    public function create(PaymentMethod $method, int $spaceId): void;

    /**
     * Deactivates or hides a payment method in the shop database that no longer exists in the API.
     *
     * Called by the synchronization algorithm for "orphaned" methods — those present
     * locally but absent from the latest API response.
     *
     * @param int $externalId The external ID of the method to deactivate.
     * @param int $spaceId The space context for this operation.
     */
    public function deactivateByExternalId(int $externalId, int $spaceId): void;
    /**
     * Returns an array of external payment method IDs currently stored in the shop's local database for this space.
     *
     * This is used by the synchronization algorithm to determine which methods
     * are new (need creation), existing (need update), or orphaned (need deactivation).
     *
     * @param int $spaceId The space context to query.
     * @return int[] External IDs of locally persisted payment methods.
     */
    public function getExistingExternalIds(int $spaceId): array;

    /**
     * Updates an existing payment method in the shop's local database.
     *
     * Called by the synchronization algorithm when an API method already
     * has a matching local record (by external ID).
     *
     * @param PaymentMethod $method The payment method with updated data.
     * @param int $spaceId The space context for this operation.
     */
    public function update(PaymentMethod $method, int $spaceId): void;
}
