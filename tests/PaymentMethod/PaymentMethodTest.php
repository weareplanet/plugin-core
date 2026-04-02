<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\PaymentMethod;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;

class PaymentMethodTest extends TestCase
{
    public function testToString(): void
    {
        $method = new PaymentMethod(
            85,
            1,
            'ACTIVE',
            'Credit Card',
            ['en-US' => 'Credit Card', 'de-DE' => 'Kreditkarte'],
            'Pay securely with CC',
            ['en-US' => 'Pay securely with CC'],
            10,
            'https://example.com/cc.png',
        );

        $json = (string) $method;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertEquals(85, $decoded['id']);
        $this->assertEquals(1, $decoded['spaceId']);
        $this->assertEquals('ACTIVE', $decoded['state']);
        $this->assertEquals('Credit Card', $decoded['name']);
        $this->assertEquals(10, $decoded['sortOrder']);
        $this->assertEquals('https://example.com/cc.png', $decoded['imageUrl']);
    }
}
