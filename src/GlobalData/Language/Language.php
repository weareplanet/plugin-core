<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\GlobalData\Language;

use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;

/**
 * Value Object representing one language the WeArePlanet Portal supports.
 *
 * This is global reference data — the same languages are available to every
 * space, so there is nothing space-specific to read here. It answers "which
 * languages can a transaction be processed in", which is what a plugin needs
 * when building a language picker or validating a shop's locale against what
 * the WeArePlanet Portal accepts.
 *
 * A single ISO2 code (e.g. 'en') can have several entries — one per country
 * variant (e.g. 'en-US', 'en-GB') — of which at most one is the primary
 * variant for that code; see {@see $primaryOfGroup} and
 * {@see LanguageCollection::findPrimary()}.
 *
 * Instances are immutable snapshots of what the API reported at read time.
 */
readonly class Language
{
    use JsonStringableTrait;

    /**
     * @param string $iso2Code The ISO 639-1 two-letter language code (e.g. 'en').
     *        Not unique on its own — see {@see $primaryOfGroup}.
     * @param string $ietfCode The IETF BCP 47 language tag (e.g. 'en-US').
     * @param string $iso3Code The ISO 639-2 three-letter language code (e.g. 'eng').
     * @param string $name The English name of the language (e.g. 'English').
     * @param string|null $countryCode The ISO 3166-1 country code this variant is
     *        associated with (e.g. 'US'), or null when the language is not tied to a
     *        specific country.
     * @param string|null $pluralExpression The CLDR plural rule expression for this
     *        language, used to select the correct plural form of a translated string,
     *        or null when the API reported none.
     * @param bool $primaryOfGroup Whether this is the primary variant among every entry
     *        sharing the same {@see $iso2Code} — e.g. 'en-US' being the default for 'en'.
     */
    public function __construct(
        public string $iso2Code,
        public string $ietfCode,
        public string $iso3Code,
        public string $name,
        public ?string $countryCode = null,
        public ?string $pluralExpression = null,
        public bool $primaryOfGroup = false,
    ) {
    }
}
