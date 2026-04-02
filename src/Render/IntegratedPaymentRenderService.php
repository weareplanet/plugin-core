<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Render;

/**
 * Service to render the integrated payment HTML.
 *
 * This service is responsible for generating the HTML content required to display
 * the integrated payment form (IFrame or Lightbox).
 */
class IntegratedPaymentRenderService
{
    /**
     * Renders the integrated payment block (Div/Button + Script).
     *
     * @param string $javascriptUrl The URL of the JavaScript file provided by the payment gateway.
     * @param int $paymentMethodConfigurationId The ID of the payment method configuration.
     * @param string $integrationMode The integration mode ('iframe' or 'lightbox').
     * @param string $containerId The ID of the HTML container element.
     * @return string The generated HTML content.
     */
    public function render(
        string $javascriptUrl,
        int $paymentMethodConfigurationId,
        string $integrationMode,
        string $containerId = 'payment-form',
    ): string {
        $html = '<script src="' . $javascriptUrl . '"></script>' . PHP_EOL;

        if ($integrationMode === 'iframe') {
            $html .= '<div id="' . $containerId . '"></div>' . PHP_EOL;
            $html .= '<div id="' . $containerId . '_errors" style="color: red; margin-top: 0.5rem;"></div>' . PHP_EOL;
            $html .= '<button id="' . $containerId . '_submit" style="margin-top: 1rem; padding: 10px 20px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Pay Now</button>' . PHP_EOL;
            $html .= '<script>' . PHP_EOL;
            $html .= '    var handler = window.IframeCheckoutHandler(' . $paymentMethodConfigurationId . ');' . PHP_EOL;
            $html .= '    handler.setValidationCallback(function(validationResult) {' . PHP_EOL;
            $html .= '        var errorContainer = document.getElementById("' . $containerId . '_errors");' . PHP_EOL;
            $html .= '        errorContainer.innerText = "";' . PHP_EOL;
            $html .= '        if (validationResult.success) {' . PHP_EOL;
            $html .= '            handler.submit();' . PHP_EOL;
            $html .= '        } else {' . PHP_EOL;
            $html .= '            if (validationResult.errors) {' . PHP_EOL;
            $html .= '                errorContainer.innerText = validationResult.errors.join("\n");' . PHP_EOL;
            $html .= '            } else {' . PHP_EOL;
            $html .= '                errorContainer.innerText = "Validation failed.";' . PHP_EOL;
            $html .= '            }' . PHP_EOL;
            $html .= '        }' . PHP_EOL;
            $html .= '    });' . PHP_EOL;
            $html .= '    handler.create("' . $containerId . '");' . PHP_EOL;
            $html .= '    document.getElementById("' . $containerId . '_submit").addEventListener("click", function() {' . PHP_EOL;
            $html .= '        handler.validate();' . PHP_EOL;
            $html .= '    });' . PHP_EOL;
            $html .= '</script>';
        } elseif ($integrationMode === 'lightbox') {
            $html .= '<button id="' . $containerId . '_btn">Pay Now</button>' . PHP_EOL;
            $html .= '<script>' . PHP_EOL;
            $html .= '    document.getElementById("' . $containerId . '_btn").addEventListener("click", function() {' . PHP_EOL;
            $html .= '        window.LightboxCheckoutHandler.startPayment(' . $paymentMethodConfigurationId . ', function(error) { alert(error); });' . PHP_EOL;
            $html .= '    });' . PHP_EOL;
            // Optional: Auto-click for testing/simulation convenience
            // $html .= '    setTimeout(function() { document.getElementById("' . $containerId . '_btn").click(); }, 1000);' . PHP_EOL;
            $html .= '</script>';
        }

        return $html;
    }
}
