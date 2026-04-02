<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tax;

use WeArePlanet\PluginCore\Render\JsonStringableTrait;

class Tax
{
    use JsonStringableTrait;

    /**
     * @param string $title The tax title (2-40 characters)
     * @param float $rate The tax rate as a percentage (e.g., 19.0 for 19%)
     */
    public function __construct(
        public readonly string $title,
        public readonly float $rate,
    ) {
        $len = mb_strlen($title);
        // SDK Constraint: Title must be 2-40 chars
        if ($len < 2 || $len > 40) {
            throw new \InvalidArgumentException("Tax title must be between 2 and 40 characters. Got: '$title'");
        }
    }
}
