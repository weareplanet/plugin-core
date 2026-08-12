<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\GlobalData\LabelDescriptorGroup;

use WeArePlanet\PluginCore\SharedKernel\AbstractCollection;

/**
 * Strictly typed, iterable collection of {@see LabelDescriptorGroup} entities.
 *
 * @extends AbstractCollection<LabelDescriptorGroup>
 */
final class LabelDescriptorGroupCollection extends AbstractCollection
{
    public function __construct(LabelDescriptorGroup ...$items)
    {
        $this->items = array_values($items);
    }

    /**
     * Finds the group with the given ID.
     *
     * @param int $id The ID of the group to look up.
     * @return LabelDescriptorGroup|null The matching group, or null when this
     *         collection has none.
     */
    public function findById(int $id): ?LabelDescriptorGroup
    {
        foreach ($this->items as $group) {
            if ($group->id === $id) {
                return $group;
            }
        }

        return null;
    }
}
