<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\PaymentMethod;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\PaymentMethod\State;

class PaymentMethodTest extends TestCase
{
    /**
     * Factory helper to construct a PaymentMethod with only the imageUrl varying.
     */
    private function createMethodWithImageUrl(?string $imageUrl): PaymentMethod
    {
        return new PaymentMethod(
            id: 1,
            spaceId: 1,
            state: State::ACTIVE,
            title: new LocalizedString([]),
            description: new LocalizedString(null),
            sortOrder: 0,
            imageUrl: $imageUrl,
        );
    }

    /**
     * Verifies that the relative path is correctly extracted after the 'resource/' segment.
     */
    public function testExtractsRelativePathCorrectly(): void
    {
        $method = $this->createMethodWithImageUrl('https://paymentshub.weareplanet.com/s/123/resource/payment/icon.svg');

        $this->assertSame('payment/icon.svg', $method->getRelativeImagePath());
    }

    /**
     * Verifies that the signature is consistent and changes when properties change.
     */
    public function testGetSignature(): void
    {
        $method = new PaymentMethod(
            85,
            1,
            State::ACTIVE,
            new LocalizedString(['en-US' => 'Credit Card']),
            new LocalizedString(['en-US' => 'Pay with CC']),
            10,
            'https://example.com/cc.png',
        );

        $signature1 = $method->getSignature();
        $this->assertIsString($signature1);
        $this->assertEquals($signature1, $method->getSignature(), 'Signature must be consistent');

        // Change title
        $method2 = new PaymentMethod(
            85,
            1,
            State::ACTIVE,
            new LocalizedString(['en-US' => 'New Title']),
            new LocalizedString(['en-US' => 'Pay with CC']),
            10,
            'https://example.com/cc.png',
        );
        $this->assertNotEquals($signature1, $method2->getSignature(), 'Signature must change when title changes');

        // Change state
        $method3 = new PaymentMethod(
            85,
            1,
            State::INACTIVE,
            new LocalizedString(['en-US' => 'Credit Card']),
            new LocalizedString(['en-US' => 'Pay with CC']),
            10,
            'https://example.com/cc.png',
        );
        $this->assertNotEquals($signature1, $method3->getSignature(), 'Signature must change when state changes');
    }

    /**
     * A null imageUrl must produce an empty string without triggering PHP 8.2 deprecation warnings.
     */
    public function testHandlesNullUrl(): void
    {
        $method = $this->createMethodWithImageUrl(null);

        $this->assertSame('', $method->getRelativeImagePath());
    }

    /**
     * When the URL does not contain a 'resource/' segment, the full URL is returned as-is.
     */
    public function testReturnsOriginalStringIfResourceNotFound(): void
    {
        $method = $this->createMethodWithImageUrl('https://paymentshub.weareplanet.com/images/icon.svg');

        $this->assertSame('https://paymentshub.weareplanet.com/images/icon.svg', $method->getRelativeImagePath());
    }

    /**
     * Verifies that query parameters (e.g. for cache busting) are stripped from the path.
     */
    public function testStripsQueryParameters(): void
    {
        $method = $this->createMethodWithImageUrl('https://paymentshub.weareplanet.com/s/123/resource/payment/twint.svg?strategy=snapshot');

        $this->assertSame('payment/twint.svg', $method->getRelativeImagePath());
    }

    /**
     * Verifies JSON serialization.
     */
    public function testToString(): void
    {
        $method = new PaymentMethod(
            85,
            1,
            State::ACTIVE,
            new LocalizedString(['en-US' => 'Credit Card']),
            new LocalizedString(['en-US' => 'Pay securely with CC']),
            10,
            'https://example.com/cc.png',
        );

        $json = (string) $method;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertEquals(85, $decoded['id']);
        $this->assertEquals(1, $decoded['spaceId']);
        $this->assertEquals('ACTIVE', $decoded['state']);
        $this->assertEquals(10, $decoded['sortOrder']);
        $this->assertEquals('https://example.com/cc.png', $decoded['imageUrl']);

        // The VO serializes to its raw data, so the title should be the array
        $this->assertIsArray($decoded['title']);
        $this->assertEquals('Credit Card', $decoded['title']['en-US']);
    }
}
