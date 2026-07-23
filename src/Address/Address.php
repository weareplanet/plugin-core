<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Address;

use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;
use WeArePlanet\PluginCore\SharedKernel\StringSanitizer;

class Address
{
    use JsonStringableTrait;

    public ?string $city = null;
    public ?string $country = null; // ISO 3166-1 alpha-2 (e.g., 'US', 'DE')
    public ?string $dependentLocality = null;
    public ?string $phoneNumber = null;
    public ?string $postalState = null; // ISO 3166-2 subdivision code, e.g., 'US-CA'
    public ?string $postcode = null;
    public ?string $sortingCode = null;
    public ?string $street = null;

    /**
     * Normalizes this address in place to satisfy gateway field constraints:
     * strips line breaks from every field, and truncates `city`, `postcode`,
     * and `street` to the gateway's maximum lengths.
     *
     * Call this after populating the address and before handing it to a
     * gateway, so oversized or multi-line shop data never reaches the API.
     */
    public function sanitize(): void
    {
        $this->city = $this->sanitizeField($this->city, 100);
        $this->country = $this->sanitizeField($this->country);
        $this->dependentLocality = $this->sanitizeField($this->dependentLocality);
        $this->phoneNumber = $this->sanitizeField($this->phoneNumber);
        $this->postalState = $this->sanitizeField($this->postalState);
        $this->postcode = $this->sanitizeField($this->postcode, 40);
        $this->sortingCode = $this->sanitizeField($this->sortingCode);
        $this->street = $this->sanitizeField($this->street, 300);
    }

    private function sanitizeField(?string $value, ?int $maxLength = null): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = StringSanitizer::stripLineBreaks($value);

        return $maxLength === null ? $value : StringSanitizer::truncate($value, $maxLength);
    }
}
