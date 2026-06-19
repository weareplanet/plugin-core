<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

/**
 * Shared helper for mapping DateTime values.
 *
 * This trait encapsulates logic for converting mutable SDK DateTime instances
 * into immutable PHP DateTimeImmutable objects, ensuring domain entities
 * remain immutable and free from side effects.
 */
trait DateTimeMapperTrait
{
    /**
     * Converts a mutable SDK DateTime to an immutable one.
     *
     * Resolves the mutable object to an immutable standard representation
     * suitable for the domain core properties.
     *
     * @param \DateTime|null $date
     * @return \DateTimeImmutable|null
     */
    protected function toDateTimeImmutable(?\DateTime $date): ?\DateTimeImmutable
    {
        if ($date === null) {
            return null;
        }

        return \DateTimeImmutable::createFromMutable($date);
    }
}
