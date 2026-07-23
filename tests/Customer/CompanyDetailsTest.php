<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Customer;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Customer\CompanyDetails;

class CompanyDetailsTest extends TestCase
{
    public function testLeavesShortOrganizationNameUntouched(): void
    {
        $details = new CompanyDetails(organizationName: 'Acme Corp');

        $this->assertSame('Acme Corp', $details->organizationName);
    }

    public function testNullOrganizationNameRemainsNull(): void
    {
        $details = new CompanyDetails();

        $this->assertNull($details->organizationName);
    }

    public function testTruncatesOrganizationNameTo100Characters(): void
    {
        $details = new CompanyDetails(organizationName: str_repeat('a', 150));

        $this->assertSame(str_repeat('a', 100), $details->organizationName);
    }
}
