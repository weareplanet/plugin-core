<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\PaymentMethod;

use WeArePlanet\PluginCore\Render\JsonStringableTrait;

/**
 * Domain entity representing a Payment Method.
 *
 * This class maps the external SDK configuration to a domain-specific
 * representation that is easier to use within the application.
 */
readonly class PaymentMethod
{
    use JsonStringableTrait;

    /**
     * @param int $id The ID of the payment method configuration.
     * @param int $spaceId The ID of the space this method belongs to.
     * @param array<string, string> $title The localized titles.
     * @param string|null $description The description of the payment method, if available.
     * @param array<string, string> $descriptionMap The localized descriptions.
     * @param int $sortOrder The sort order for display purposes.
     * @param string|null $imageUrl The URL of the payment method image, if available.
     */
    public function __construct(
        public int $id,
        public int $spaceId,
        public string $state,
        public string $name,
        public array $title,
        public ?string $description,
        public array $descriptionMap,
        public int $sortOrder,
        public ?string $imageUrl,
    ) {
    }
}
