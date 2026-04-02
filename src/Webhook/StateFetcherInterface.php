<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Http\Request;

/**
 * Defines the contract for a class that can resolve the state of a webhook.
 * Implementations can use various strategies, such as API calls or payload decryption.
 */
interface StateFetcherInterface
{
    /**
     * Fetches the clear-text state for a given webhook request.
     *
     * @param Request $request The incoming request object.
     * @param int $entityId The ID of the primary entity associated with the webhook.
     * @return string The resolved state.
     * @throws \Exception If the state cannot be resolved.
     */
    public function fetchState(Request $request, int $entityId): string;
}
