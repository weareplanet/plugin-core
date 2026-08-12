<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\GlobalData\LabelDescriptor;

use WeArePlanet\PluginCore\SharedKernel\AbstractCollection;

/**
 * Strictly typed, iterable collection of {@see LabelDescriptor} entities.
 *
 * @extends AbstractCollection<LabelDescriptor>
 */
final class LabelDescriptorCollection extends AbstractCollection
{
    public function __construct(LabelDescriptor ...$items)
    {
        $this->items = array_values($items);
    }

    /**
     * Returns every descriptor belonging to the given group.
     *
     * @param int $groupId The ID of the group to filter by.
     * @return list<LabelDescriptor> The matching descriptors, in this collection's order.
     *         Empty when this collection has no descriptor of that group.
     */
    public function findByGroup(int $groupId): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (LabelDescriptor $descriptor): bool => $descriptor->groupId === $groupId,
        ));
    }

    /**
     * Finds the descriptor with the given ID.
     *
     * @param int $id The ID of the descriptor to look up — matches
     *        {@see \WeArePlanet\PluginCore\Charge\Attempt\Label::$descriptorId}.
     * @return LabelDescriptor|null The matching descriptor, or null when this
     *         collection has none.
     */
    public function findById(int $id): ?LabelDescriptor
    {
        foreach ($this->items as $descriptor) {
            if ($descriptor->id === $id) {
                return $descriptor;
            }
        }

        return null;
    }
}
