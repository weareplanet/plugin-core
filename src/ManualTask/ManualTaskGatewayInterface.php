<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\ManualTask;

interface ManualTaskGatewayInterface
{
    /**
     * Counts the manual tasks in the given space that are in the given state.
     *
     * @param int $spaceId The space to count manual tasks in.
     * @param State $state The manual task state to filter by.
     * @return int The number of matching manual tasks.
     */
    public function countByState(int $spaceId, State $state): int;
}
