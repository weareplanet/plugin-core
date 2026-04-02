<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Address;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Address\Address;

class AddressTest extends TestCase
{
    public function testToString(): void
    {
        $address = new Address();
        $address->city = 'Winterthur';
        $address->country = 'CH';
        $address->emailAddress = 'test@example.com';
        $address->givenName = 'Test';
        $address->familyName = 'User';

        $json = (string) $address;
        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertEquals('Winterthur', $decoded['city']);
        $this->assertEquals('CH', $decoded['country']);
        $this->assertEquals('test@example.com', $decoded['emailAddress']);
        $this->assertEquals('Test', $decoded['givenName']);
        $this->assertEquals('User', $decoded['familyName']);
    }
}
