<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

/**
 * Value object wrapping the URL a customer is redirected to (or that is embedded)
 * to complete a payment.
 *
 * Encapsulating the raw string keeps the domain free of primitive obsession and
 * gives callers a single, type-safe representation regardless of the integration
 * mode (payment page, iframe, lightbox).
 */
final class PaymentUrl implements \Stringable, \JsonSerializable
{
    public function __construct(
        public readonly string $value,
    ) {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
