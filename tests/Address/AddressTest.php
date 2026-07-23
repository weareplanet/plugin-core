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
        $address->street = 'Musterstrasse 1';
        $address->postcode = '8400';

        $json = (string) $address;
        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertEquals('Winterthur', $decoded['city']);
        $this->assertEquals('CH', $decoded['country']);
        $this->assertEquals('Musterstrasse 1', $decoded['street']);
        $this->assertEquals('8400', $decoded['postcode']);
    }

    public function testSanitizeTruncatesCity(): void
    {
        $address = new Address();
        $address->city = str_repeat('a', 150);

        $address->sanitize();

        $this->assertSame(str_repeat('a', 100), $address->city);
    }

    public function testSanitizeTruncatesPostcode(): void
    {
        $address = new Address();
        $address->postcode = str_repeat('1', 50);

        $address->sanitize();

        $this->assertSame(str_repeat('1', 40), $address->postcode);
    }

    public function testSanitizeTruncatesStreet(): void
    {
        $address = new Address();
        $address->street = str_repeat('a', 350);

        $address->sanitize();

        $this->assertSame(str_repeat('a', 300), $address->street);
    }

    public function testSanitizeStripsLineBreaksFromEveryField(): void
    {
        $address = new Address();
        $address->city = "Zurich\nCity";
        $address->country = "C\rH";
        $address->dependentLocality = "Unit\n5";
        $address->phoneNumber = "123\r456";
        $address->postalState = "ZH\n";
        $address->postcode = "80\r00";
        $address->sortingCode = "SC\n1";
        $address->street = "Bahnhofstrasse\r1";

        $address->sanitize();

        $this->assertSame('Zurich City', $address->city);
        $this->assertSame('C H', $address->country);
        $this->assertSame('Unit 5', $address->dependentLocality);
        $this->assertSame('123 456', $address->phoneNumber);
        $this->assertSame('ZH ', $address->postalState);
        $this->assertSame('80 00', $address->postcode);
        $this->assertSame('SC 1', $address->sortingCode);
        $this->assertSame('Bahnhofstrasse 1', $address->street);
    }

    public function testSanitizeLeavesNullFieldsAsNull(): void
    {
        $address = new Address();

        $address->sanitize();

        $this->assertNull($address->city);
        $this->assertNull($address->country);
        $this->assertNull($address->dependentLocality);
        $this->assertNull($address->phoneNumber);
        $this->assertNull($address->postalState);
        $this->assertNull($address->postcode);
        $this->assertNull($address->sortingCode);
        $this->assertNull($address->street);
    }
}
