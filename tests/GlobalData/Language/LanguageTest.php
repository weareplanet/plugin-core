<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\GlobalData\Language;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\GlobalData\Language\Language;
use WeArePlanet\PluginCore\GlobalData\Language\LanguageCollection;

class LanguageTest extends TestCase
{
    public function testFindPrimaryIsCaseInsensitive(): void
    {
        $primary = new Language('en', 'en-US', 'eng', 'English', 'US', null, true);
        $collection = new LanguageCollection($primary);

        $this->assertSame($primary, $collection->findPrimary('EN'));
    }

    public function testFindPrimaryReturnsNullWhenNoVariantIsMarkedPrimary(): void
    {
        $collection = new LanguageCollection(
            new Language('en', 'en-GB', 'eng', 'English', 'GB', null, false),
        );

        $this->assertNull($collection->findPrimary('en'));
    }

    public function testFindPrimaryReturnsNullWhenTheCodeIsUnknown(): void
    {
        $collection = new LanguageCollection(
            new Language('en', 'en-US', 'eng', 'English', 'US', null, true),
        );

        $this->assertNull($collection->findPrimary('fr'));
    }

    public function testFindPrimaryReturnsThePrimaryVariantForTheGivenIso2Code(): void
    {
        $primary = new Language('en', 'en-US', 'eng', 'English', 'US', null, true);
        $collection = new LanguageCollection(
            new Language('en', 'en-GB', 'eng', 'English', 'GB', null, false),
            $primary,
            new Language('de', 'de-DE', 'deu', 'German', 'DE', null, true),
        );

        $this->assertSame($primary, $collection->findPrimary('en'));
    }

    public function testLanguageIsImmutable(): void
    {
        $language = new Language('en', 'en-US', 'eng', 'English');

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line Intentionally writing to a readonly property.
        $language->iso2Code = 'de';
    }

    public function testOptionalPropertiesDefaultToNullOrFalse(): void
    {
        $language = new Language('en', 'en-US', 'eng', 'English');

        $this->assertNull($language->countryCode);
        $this->assertNull($language->pluralExpression);
        $this->assertFalse($language->primaryOfGroup);
    }
    public function testToString(): void
    {
        $language = new Language(
            iso2Code: 'en',
            ietfCode: 'en-US',
            iso3Code: 'eng',
            name: 'English',
            countryCode: 'US',
            pluralExpression: 'n != 1',
            primaryOfGroup: true,
        );

        $json = (string) $language;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertSame('en', $decoded['iso2Code']);
        $this->assertSame('en-US', $decoded['ietfCode']);
        $this->assertSame('eng', $decoded['iso3Code']);
        $this->assertSame('English', $decoded['name']);
        $this->assertSame('US', $decoded['countryCode']);
        $this->assertTrue($decoded['primaryOfGroup']);
    }
}
