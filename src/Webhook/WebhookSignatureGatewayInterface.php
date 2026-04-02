<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

/**
 * Interface WebhookSignatureGatewayInterface
 *
 * Defines the contract for verifying webhook signatures.
 */
interface WebhookSignatureGatewayInterface
{
    /**
     * Validates the payload signature.
     *
     * @param string $signatureHeader The signature string from the request headers.
     * @param string $payload The raw request body content.
     * @return bool True if the signature is valid, false otherwise.
     */
    public function validate(string $signatureHeader, string $payload): bool;
}
