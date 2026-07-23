<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tax;

use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;
use WeArePlanet\PluginCore\SharedKernel\StringSanitizer;

class Tax
{
    use JsonStringableTrait;

    public readonly string $title;

    /**
     * @param string $title The tax title (at least 2 characters; silently
     *                      truncated to 40 if longer).
     * @param float $rate The tax rate as a percentage (e.g., 19.0 for 19%)
     */
    public function __construct(
        string $title,
        public readonly float $rate,
    ) {
        // SDK Constraint: Title must be at least 2 characters. There's no
        // sensible way to self-sanitize a too-short title, so this is the
        // one case that still fails fast rather than silently truncating.
        if (mb_strlen($title) < 2) {
            throw new \InvalidArgumentException("Tax title must be at least 2 characters. Got: '$title'");
        }

        $this->title = StringSanitizer::truncate($title, 40);
    }
}
