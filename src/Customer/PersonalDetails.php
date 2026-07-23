<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Customer;

use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;
use WeArePlanet\PluginCore\SharedKernel\StringSanitizer;

/**
 * Identity data describing the customer as a person, independent of any
 * postal address.
 *
 * `$familyName` and `$givenName` are truncated to 100 characters and
 * `$salutation` to 20, matching the gateway's field constraints — this DTO
 * is immutable, so these are self-sanitized in the constructor rather than
 * via a later `sanitize()` call.
 */
class PersonalDetails
{
    use JsonStringableTrait;

    public readonly ?string $familyName;
    public readonly ?string $givenName;
    public readonly ?string $salutation;

    public function __construct(
        public readonly ?\DateTimeImmutable $dateOfBirth = null,
        public readonly ?string $emailAddress = null,
        ?string $familyName = null,
        public readonly ?Gender $gender = null,
        ?string $givenName = null,
        public readonly ?string $mobilePhoneNumber = null,
        ?string $salutation = null, // e.g., 'Mrs', 'Mr', 'Dr'
        public readonly ?string $socialSecurityNumber = null,
    ) {
        $this->familyName = $familyName !== null ? StringSanitizer::truncate($familyName, 100) : null;
        $this->givenName = $givenName !== null ? StringSanitizer::truncate($givenName, 100) : null;
        $this->salutation = $salutation !== null ? StringSanitizer::truncate($salutation, 20) : null;
    }
}
