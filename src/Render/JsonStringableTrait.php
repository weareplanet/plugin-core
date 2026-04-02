<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Render;

/**
 * Trait JsonStringableTrait
 * Provides a standard JSON string representation for DTOs and entities for logging and rendering.
 */
trait JsonStringableTrait
{
    public function __toString(): string
    {
        $json = json_encode(get_object_vars($this), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json ?: '{}';
    }
}
