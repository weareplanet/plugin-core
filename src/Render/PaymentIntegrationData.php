<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Render;

/**
 * DTO containing the raw data required to initialize a payment form in headless or reactive environments.
 *
 * This immutable object holds the core configuration parameters needed by the frontend
 * to load the appropriate JavaScript SDK and initialize the correct payment handler.
 */
readonly class PaymentIntegrationData
{
    use JsonStringableTrait;

    /**
     * Initializes a new instance of the PaymentIntegrationData class.
     *
     * @param string $javascriptUrl The URL of the JavaScript file provided by the payment gateway.
     * @param int $paymentMethodConfigurationId The ID of the payment method configuration.
     * @param string $integrationMode The integration mode (e.g., 'iframe' or 'lightbox').
     */
    public function __construct(
        public string $javascriptUrl,
        public int $paymentMethodConfigurationId,
        public string $integrationMode,
    ) {
    }
}
