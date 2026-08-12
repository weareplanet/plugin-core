<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Sdk\ClientMetadata;

class ClientMetadataTest extends TestCase
{
    /**
     * Version strings and the combined identifier each must produce.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function shopSystemAndVersionProvider(): array
    {
        return [
            'major.minor.patch drops the patch' => ['magento', '2.4.9', 'magento-2.4'],
            'a later patch groups the same way' => ['magento', '2.4.10', 'magento-2.4'],
            'major.minor is already correct' => ['magento', '2.4', 'magento-2.4'],
            'a single segment is used as-is' => ['woocommerce', '9', 'woocommerce-9'],
            'four segments are truncated to two' => ['magento', '2.4.9.1', 'magento-2.4'],
            'the system name is lowercased' => ['Magento', '2.4.9', 'magento-2.4'],
            'a blank version yields no separator' => ['magento', '', 'magento'],
            'a non-numeric suffix stays on the minor' => ['shopware', '6.5-RC1', 'shopware-6.5-RC1'],
        ];
    }

    public function testToHeadersReturnsAllFourHeaders(): void
    {
        $headers = (new ClientMetadata('magento', '2.4.9', '1.2.0'))->toHeaders();

        $this->assertSame([
            'x-meta-shop-system' => 'magento',
            'x-meta-shop-system-version' => '2.4.9',
            'x-meta-shop-system-and-version' => 'magento-2.4',
            'x-meta-plugin-version' => '1.2.0',
        ], $headers);
    }

    public function testToHeadersKeysMatchThePublishedConstants(): void
    {
        // Consumers key off the constants; a rename that missed toHeaders() would make
        // the two disagree silently.
        $headers = (new ClientMetadata('magento', '2.4.9', '1.2.0'))->toHeaders();

        $this->assertArrayHasKey(ClientMetadata::HEADER_SHOP_SYSTEM, $headers);
        $this->assertArrayHasKey(ClientMetadata::HEADER_SHOP_SYSTEM_VERSION, $headers);
        $this->assertArrayHasKey(ClientMetadata::HEADER_SHOP_SYSTEM_AND_VERSION, $headers);
        $this->assertArrayHasKey(ClientMetadata::HEADER_PLUGIN_VERSION, $headers);
    }

    /**
     * @dataProvider shopSystemAndVersionProvider
     */
    public function testCombinedHeaderUsesMajorAndMinorVersionOnly(
        string $shopSystem,
        string $shopSystemVersion,
        string $expected,
    ): void {
        $metadata = new ClientMetadata($shopSystem, $shopSystemVersion, '1.2.0');

        $this->assertSame($expected, $metadata->toHeaders()['x-meta-shop-system-and-version']);
    }

    public function testTheFullVersionHeaderIsNotTruncated(): void
    {
        // Only the combined header drops the patch; the dedicated version header must
        // still report exactly what the platform said.
        $headers = (new ClientMetadata('magento', '2.4.9', '1.2.0'))->toHeaders();

        $this->assertSame('2.4.9', $headers['x-meta-shop-system-version']);
        $this->assertSame('magento-2.4', $headers['x-meta-shop-system-and-version']);
    }

    public function testTheSystemNameHeaderKeepsItsOriginalCasing(): void
    {
        // Lowercasing applies to the combined identifier only.
        $headers = (new ClientMetadata('Magento', '2.4.9', '1.2.0'))->toHeaders();

        $this->assertSame('Magento', $headers['x-meta-shop-system']);
        $this->assertSame('magento-2.4', $headers['x-meta-shop-system-and-version']);
    }

    public function testTheValueObjectIsImmutable(): void
    {
        // Asserted through reflection rather than by writing to a property: the write
        // would be a compile-visible error that static analysis rejects outright, and
        // suppressing that to test it is not worth it.
        $this->assertTrue((new \ReflectionClass(ClientMetadata::class))->isReadOnly());
    }
}
