<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\GlobalData\Language;

use WeArePlanet\PluginCore\SharedKernel\AbstractCollection;

/**
 * Strictly typed, iterable collection of {@see Language} entities.
 *
 * @extends AbstractCollection<Language>
 */
final class LanguageCollection extends AbstractCollection
{
    public function __construct(Language ...$items)
    {
        $this->items = array_values($items);
    }

    /**
     * Finds the primary language variant for the given ISO2 code.
     *
     * A two-letter code such as 'en' can have several entries in this collection —
     * one per country variant (e.g. 'en-US', 'en-GB') — of which at most one is
     * marked as primary. This is the resolution a shop typically needs when it only
     * stores the two-letter code and must pick one concrete variant to send the API.
     *
     * @param string $iso2Code The ISO 639-1 two-letter code to resolve (e.g. 'en'),
     *        matched case-insensitively.
     * @return Language|null The primary variant for that code, or null when this
     *         collection has no entry marked primary for it.
     */
    public function findPrimary(string $iso2Code): ?Language
    {
        foreach ($this->items as $language) {
            if ($language->primaryOfGroup && strcasecmp($language->iso2Code, $iso2Code) === 0) {
                return $language;
            }
        }

        return null;
    }
}
