<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Localization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Localization\LocalizedString;

/**
 * Exhaustive tests for the LocalizedString Value Object.
 */
class LocalizedStringTest extends TestCase
{
    /**
     * @return array<string, array{array<string, string>, string, string, ?string}>
     */
    public static function resolutionPriorityProvider(): array
    {
        return [
            'exact match wins over language prefix' => [
                ['de-DE' => 'Exact', 'de-CH' => 'Prefix'],
                'de-DE',
                'en-US',
                'Exact',
            ],
            'language prefix wins over fallback' => [
                ['de-CH' => 'Prefix', 'en-US' => 'Fallback'],
                'de-AT',
                'en-US',
                'Prefix',
            ],
            'fallback wins over first value' => [
                ['ja-JP' => 'First', 'en-US' => 'Fallback'],
                'ko-KR',
                'en-US',
                'Fallback',
            ],
            'first value is the last resort' => [
                ['ja-JP' => 'First', 'zh-CN' => 'Second'],
                'ko-KR',
                'en-US',
                'First',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Absolute fallback (first array value)
    // ------------------------------------------------------------------

    public function testAbsoluteFallbackReturnsFirstValue(): void
    {
        $vo = new LocalizedString([
            'ja-JP' => 'クレジットカード',
            'zh-CN' => '信用卡',
        ]);

        // No match for 'fr-FR', no en-US fallback, returns first value
        $this->assertSame('クレジットカード', $vo->localize('fr-FR'));
    }

    public function testCustomFallbackLocale(): void
    {
        $vo = new LocalizedString([
            'ja-JP' => 'クレジットカード',
            'de-DE' => 'Kreditkarte',
        ]);

        // No 'fr' prefix or 'de-DE' fallback match, but custom fallback is de-DE
        $this->assertSame('Kreditkarte', $vo->localize('fr-FR', 'de-DE'));
    }

    public function testEmptyArrayReturnsNull(): void
    {
        $vo = new LocalizedString([]);

        $this->assertNull($vo->localize('en-US'));
    }

    // ------------------------------------------------------------------
    // Exact locale match
    // ------------------------------------------------------------------

    public function testExactLocaleMatch(): void
    {
        $vo = new LocalizedString([
            'de-DE' => 'Kreditkarte',
            'en-US' => 'Credit Card',
        ]);

        $this->assertSame('Kreditkarte', $vo->localize('de-DE'));
        $this->assertSame('Credit Card', $vo->localize('en-US'));
    }

    // ------------------------------------------------------------------
    // Edge cases
    // ------------------------------------------------------------------

    public function testExactMatchTakesPriorityOverLanguagePrefix(): void
    {
        $vo = new LocalizedString([
            'de-CH' => 'Kreditkarte (CH)',
            'de-DE' => 'Kreditkarte (DE)',
        ]);

        // de-DE has an exact match, should NOT return de-CH via prefix
        $this->assertSame('Kreditkarte (DE)', $vo->localize('de-DE'));
    }

    // ------------------------------------------------------------------
    // Fallback locale
    // ------------------------------------------------------------------

    public function testFallbackLocale(): void
    {
        $vo = new LocalizedString([
            'ja-JP' => 'クレジットカード',
            'en-US' => 'Credit Card',
        ]);

        // 'fr-FR' has no exact or language match, explicit fallback to en-US
        $this->assertSame('Credit Card', $vo->localize('fr-FR', 'en-US'));
    }

    public function testGetDefaultReturnsEmptyStringForEmptyArray(): void
    {
        $vo = new LocalizedString([]);

        $this->assertSame('', $vo->getDefault());
    }

    public function testGetDefaultReturnsEmptyStringForNull(): void
    {
        $vo = new LocalizedString(null);

        $this->assertSame('', $vo->getDefault());
    }

    public function testGetDefaultReturnsFirstArrayValue(): void
    {
        $vo = new LocalizedString([
            'de-DE' => 'Kreditkarte',
            'en-US' => 'Credit Card',
        ]);

        $this->assertSame('Kreditkarte', $vo->getDefault());
    }

    // ------------------------------------------------------------------
    // getDefault() — locale-agnostic retrieval
    // ------------------------------------------------------------------

    public function testGetDefaultReturnsStringDirectly(): void
    {
        $vo = new LocalizedString('Credit Card');

        $this->assertSame('Credit Card', $vo->getDefault());
    }

    public function testJsonSerializePreservesArray(): void
    {
        $data = ['en-US' => 'Credit Card', 'de-DE' => 'Kreditkarte'];
        $vo = new LocalizedString($data);

        $this->assertSame($data, $vo->jsonSerialize());
        $this->assertSame('{"en-US":"Credit Card","de-DE":"Kreditkarte"}', json_encode($vo));
    }

    public function testJsonSerializePreservesNull(): void
    {
        $vo = new LocalizedString(null);

        $this->assertNull($vo->jsonSerialize());
        $this->assertSame('null', json_encode($vo));
    }

    // ------------------------------------------------------------------
    // jsonSerialize preserves raw data for JsonStringableTrait
    // ------------------------------------------------------------------

    public function testJsonSerializePreservesString(): void
    {
        $vo = new LocalizedString('Direct');

        $this->assertSame('Direct', $vo->jsonSerialize());
        $this->assertSame('"Direct"', json_encode($vo));
    }

    // ------------------------------------------------------------------
    // Language-prefix fallback (de-AT should find de-CH if de-AT is absent)
    // ------------------------------------------------------------------

    public function testLanguagePrefixFallback(): void
    {
        $vo = new LocalizedString([
            'de-CH' => 'Kreditkarte (CH)',
            'en-US' => 'Credit Card',
        ]);

        // 'de-AT' is not present, but 'de-CH' shares the same language prefix
        $this->assertSame('Kreditkarte (CH)', $vo->localize('de-AT'));
    }

    public function testLanguagePrefixFallbackReturnsFirstMatchingKey(): void
    {
        $vo = new LocalizedString([
            'fr-FR' => 'Carte de crédit',
            'de-DE' => 'Kreditkarte (DE)',
            'de-CH' => 'Kreditkarte (CH)',
            'en-US' => 'Credit Card',
        ]);

        // 'de-AT' should match the first 'de-*' key encountered (de-DE)
        $this->assertSame('Kreditkarte (DE)', $vo->localize('de-AT'));
    }

    // ------------------------------------------------------------------
    // Null and empty input
    // ------------------------------------------------------------------

    public function testNullInputReturnsNull(): void
    {
        $vo = new LocalizedString(null);

        $this->assertNull($vo->localize('en-US'));
    }

    /**
     * Verifies the full priority chain: exact > language > fallback > first.
     *
     * @param array<string, string> $data
     */
    #[DataProvider('resolutionPriorityProvider')]
    public function testResolutionPriority(
        array $data,
        string $shopLocale,
        string $fallbackLocale,
        ?string $expected,
    ): void {
        $vo = new LocalizedString($data);

        $this->assertSame($expected, $vo->localize($shopLocale, $fallbackLocale));
    }

    public function testStringInputIgnoresLocaleArguments(): void
    {
        $vo = new LocalizedString('Fixed Value');

        $this->assertSame('Fixed Value', $vo->localize('fr-FR', 'ja-JP'));
    }
    // ------------------------------------------------------------------
    // String input (pre-resolved by the API)
    // ------------------------------------------------------------------

    public function testStringInputReturnsDirectly(): void
    {
        $vo = new LocalizedString('Credit Card');

        $this->assertSame('Credit Card', $vo->localize('de-DE'));
    }

    public function testUnderscoreLocaleWithLanguagePrefixFallback(): void
    {
        $vo = new LocalizedString([
            'de-CH' => 'Kreditkarte (CH)',
            'en-US' => 'Credit Card',
        ]);

        // 'de_AT' normalized to 'de-AT', then language prefix fallback to 'de-CH'
        $this->assertSame('Kreditkarte (CH)', $vo->localize('de_AT'));
    }

    // ------------------------------------------------------------------
    // Underscore normalization (shop systems use en_US, API uses en-US)
    // ------------------------------------------------------------------

    public function testUnderscoreNormalization(): void
    {
        $vo = new LocalizedString([
            'en-US' => 'Credit Card',
            'de-DE' => 'Kreditkarte',
        ]);

        // Shop locale 'en_US' should match the 'en-US' key
        $this->assertSame('Credit Card', $vo->localize('en_US'));
        $this->assertSame('Kreditkarte', $vo->localize('de_DE'));
    }
}
