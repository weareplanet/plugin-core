<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\SharedKernel;

/**
 * Immutable value object wrapping a validated absolute URL.
 */
final class Url implements \Stringable, \JsonSerializable
{
    public function __construct(
        public readonly string $value,
    ) {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Invalid URL format.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
