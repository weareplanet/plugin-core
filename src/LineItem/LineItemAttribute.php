<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\LineItem;

use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;
use WeArePlanet\PluginCore\SharedKernel\StringSanitizer;

/**
 * Custom attribute attached to a {@see LineItem}.
 *
 * The portal renders attributes as `label: value` pairs (e.g. "Size: M"), so
 * both halves are first-class on this DTO instead of being collapsed into a
 * plain string.
 *
 * `$id` and `$value` are self-sanitized in the constructor (the gateway
 * payload key must be lowercase alphanumeric, at most 40 characters; the
 * value is capped at 512 characters), since this DTO is immutable and has
 * no later point at which to normalize them.
 */
class LineItemAttribute
{
    use JsonStringableTrait;

    public readonly string $id;
    public readonly string $value;

    /**
     * @param string $id Portal-side attribute identifier (e.g. "option_144"),
     *                   used as the payload key when sent to the gateway.
     * @param string $label Human-readable attribute label (e.g. "Size").
     * @param string $value Attribute value (e.g. "M").
     */
    public function __construct(
        string $id,
        public readonly string $label,
        string $value,
    ) {
        $this->id = self::sanitizeKey($id);
        $this->value = StringSanitizer::truncate($value, 512);
    }

    private static function sanitizeKey(string $id): string
    {
        $key = preg_replace('/[^a-z0-9]/', '', strtolower($id)) ?? '';

        return StringSanitizer::truncate($key, 40);
    }
}
