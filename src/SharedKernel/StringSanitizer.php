<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\SharedKernel;

/**
 * Reusable string normalization for DTOs that self-sanitize before their
 * data reaches the gateway (e.g. enforcing max field lengths, stripping
 * characters the API payload cannot carry).
 */
final class StringSanitizer
{
    /**
     * Truncates a string to at most $length characters, counting
     * multi-byte characters (e.g. accented letters, CJK) as a single
     * character rather than by raw byte count.
     */
    public static function truncate(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }

    /**
     * Replaces carriage returns and line feeds with spaces, since gateway
     * fields typically expect a single line of text.
     */
    public static function stripLineBreaks(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', $value);
    }
}
