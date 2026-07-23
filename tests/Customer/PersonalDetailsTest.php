<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Customer;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Customer\PersonalDetails;

class PersonalDetailsTest extends TestCase
{
    public function testLeavesShortValuesUntouched(): void
    {
        $details = new PersonalDetails(
            familyName: 'Doe',
            givenName: 'John',
            salutation: 'Mr',
        );

        $this->assertSame('Doe', $details->familyName);
        $this->assertSame('John', $details->givenName);
        $this->assertSame('Mr', $details->salutation);
    }

    public function testNullValuesRemainNull(): void
    {
        $details = new PersonalDetails();

        $this->assertNull($details->familyName);
        $this->assertNull($details->givenName);
        $this->assertNull($details->salutation);
    }

    public function testTruncatesFamilyNameTo100Characters(): void
    {
        $details = new PersonalDetails(familyName: str_repeat('a', 150));

        $this->assertSame(str_repeat('a', 100), $details->familyName);
    }

    public function testTruncatesGivenNameTo100Characters(): void
    {
        $details = new PersonalDetails(givenName: str_repeat('b', 150));

        $this->assertSame(str_repeat('b', 100), $details->givenName);
    }

    public function testTruncatesSalutationTo20Characters(): void
    {
        $details = new PersonalDetails(salutation: str_repeat('c', 30));

        $this->assertSame(str_repeat('c', 20), $details->salutation);
    }
}
