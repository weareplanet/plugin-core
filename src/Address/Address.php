<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Address;

use WeArePlanet\PluginCore\Render\JsonStringableTrait;

class Address
{
    use JsonStringableTrait;

    public string $city;
    public string $country; // ISO 3166-1 alpha-2 (e.g., 'US', 'DE')
    public ?\DateTimeImmutable $dateOfBirth = null;
    public ?string $emailAddress = null;
    public ?string $familyName = null;
    public ?string $givenName = null;
    public ?string $organizationName = null;
    public ?string $phoneNumber = null;
    public ?string $postcode = null;
    public ?string $salesTaxNumber = null;
    public ?string $salutation = null; // e.g., 'Mrs', 'Mr', 'Dr'
    public ?string $street = null;
}
