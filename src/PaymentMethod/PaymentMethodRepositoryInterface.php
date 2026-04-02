<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\PaymentMethod;

interface PaymentMethodRepositoryInterface
{
    /**
     * Syncs the provided list of payment methods to the local database.
     * * The implementation should:
     * 1. Update existing methods (by ID).
     * 2. Insert new methods.
     * 3. (Optional) Disable/Delete methods for this Space that are NOT in the list.
     *
     * @param int $spaceId The space context for this sync.
     * @param PaymentMethod[] $paymentMethods The list of active methods from WeArePlanet.
     */
    public function sync(int $spaceId, array $paymentMethods): void;
}
