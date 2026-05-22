<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Localization;

/**
 * Value Object encapsulating localized API data (titles, descriptions, failure reasons).
 *
 * The API returns localized content either as a plain string (pre-resolved)
 * or as an associative array keyed by locale (e.g. ['en-US' => 'Credit Card', 'de-DE' => 'Kreditkarte']).
 * This VO centralizes the resolution strategy so client plugins never need to handle
 * locale normalization or fallback chains themselves.
 */
readonly class LocalizedString implements \JsonSerializable
{
    /**
     * @param string|array<string, string>|null $data Raw localized data from the SDK.
     */
    public function __construct(
        private string|array|null $data,
    ) {
    }

    /**
     * Returns the default (locale-agnostic) string representation.
     *
     * Intended for contexts where no specific locale is available,
     * such as alphabetical sorting or admin-facing fallback labels.
     */
    public function getDefault(): string
    {
        if (is_string($this->data)) {
            return $this->data;
        }

        if ($this->data === null || $this->data === []) {
            return '';
        }

        $values = array_values($this->data);
        return $values[0] ?? '';
    }

    /**
     * Preserves the raw data structure for JSON serialization,
     * ensuring JsonStringableTrait output remains unchanged.
     *
     * @return string|array<string, string>|null
     */
    public function jsonSerialize(): string|array|null
    {
        return $this->data;
    }

    /**
     * Resolves the localized data to a single string for the given shop locale.
     *
     * Resolution strategy (first match wins):
     * - If the data is a plain string, return it directly (already resolved by the API).
     * - If the data is null or an empty array, return null.
     * - Exact match on the normalized locale key (e.g. 'de-DE').
     * - Language-prefix match: extract the primary language tag (e.g. 'de' from 'de-AT')
     *   and return the first key that shares the same language.
     * - Fallback to the explicit $fallbackLocale key.
     * - Absolute fallback: return the first value in the array.
     *
     * @param string $shopLocale The shop's locale, accepting both 'en-US' and 'en_US' formats.
     * @param string|null $fallbackLocale Optional explicit fallback locale to try before the absolute fallback.
     * @return string|null The resolved string, or null if no data is available.
     */
    public function localize(
        string $shopLocale,
        ?string $fallbackLocale = null,
    ): ?string {
        if (is_string($this->data)) {
            return $this->data;
        }

        if ($this->data === null || $this->data === []) {
            return null;
        }

        // Normalize underscore-based locales (en_US) to hyphen format (en-US)
        $normalizedLocale = str_replace('_', '-', $shopLocale);

        // Exact match on the normalized locale
        if (isset($this->data[$normalizedLocale])) {
            return $this->data[$normalizedLocale];
        }

        // Language-prefix match: 'de-AT' extracts 'de', then finds the first 'de-*' key
        $language = strtok($normalizedLocale, '-');
        $languagePrefix = $language . '-';
        foreach ($this->data as $key => $value) {
            if (str_starts_with($key, $languagePrefix)) {
                return $value;
            }
        }

        // Explicit fallback locale, when provided by the caller
        if ($fallbackLocale !== null && isset($this->data[$fallbackLocale])) {
            return $this->data[$fallbackLocale];
        }

        // Absolute fallback: delegate to getDefault()
        return $this->getDefault() ?: null;
    }
}
