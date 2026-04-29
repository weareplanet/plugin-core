<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Render;

/**
 * Data Transfer Object for payment rendering options.
 *
 * This class provides a structured way to pass configuration options to the
 * IntegratedPaymentRenderService, allowing for clean and type-safe customization
 * of the rendered HTML.
 */
readonly class RenderOptions
{
    use JsonStringableTrait;


    /**
     * Initializes a new instance of the RenderOptions class.
     *
     * @param string $containerId The ID of the HTML container where the iframe/form will be rendered.
     * @param string $buttonText The text to display on the submit button.
     * @param string $buttonClass The CSS class for the submit button.
     * @param string $errorContainerClass The CSS class for the error message container.
     * @param string $fallbackErrorMessage The default error message to show if validation fails without specific errors.
     * @param string|null $nonce The Content Security Policy (CSP) nonce to apply to script tags.
     */
    public function __construct(
        public string $containerId = 'payment-form',
        public string $buttonText = 'Pay Now',
        public string $buttonClass = 'payment-submit-button',
        public string $errorContainerClass = 'payment-error-container',
        public string $fallbackErrorMessage = 'Validation failed.',
        public ?string $nonce = null,
    ) {
    }
}
