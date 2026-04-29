<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Render;

/**
 * Service to render the integrated payment HTML and JavaScript.
 *
 * This service provides multiple output formats to support traditional SSR,
 * modern reactive frontends, and CSP-compliant environments.
 */
class IntegratedPaymentRenderService
{
    /**
     * Returns the raw metadata for headless or reactive frontend integrations (e.g., Alpine.js, React).
     *
     * This method provides the essential configuration data needed by frontend frameworks
     * to manage the payment integration lifecycle independently.
     *
     * @param string $javascriptUrl The URL of the JavaScript file for the payment gateway.
     * @param int $paymentMethodConfigurationId The ID of the payment method configuration.
     * @param string $integrationMode The integration mode ('iframe' or 'lightbox').
     * @return PaymentIntegrationData The metadata DTO.
     */
    public function getMetadata(
        string $javascriptUrl,
        int $paymentMethodConfigurationId,
        string $integrationMode,
    ): PaymentIntegrationData {
        return new PaymentIntegrationData($javascriptUrl, $paymentMethodConfigurationId, $integrationMode);
    }

    /**
     * Renders the complete HTML block including UI elements (Divs/Buttons) and JavaScript.
     *
     * This method is ideal for traditional Server-Side Rendered (SSR) environments where
     * the full payment block should be injected into the page as a single string.
     *
     * @param PaymentIntegrationData $data The payment integration metadata.
     * @param RenderOptions|null $options The options for rendering the payment block.
     * @return string The complete HTML content.
     */
    public function renderHtml(PaymentIntegrationData $data, ?RenderOptions $options = null): string
    {
        $options = $options ?? new RenderOptions();
        $uiHtml = '';

        if ($data->integrationMode === 'iframe') {
            $uiHtml = <<<HTML
<div id="{$options->containerId}"></div>
<div id="{$options->containerId}_errors" class="{$options->errorContainerClass}"></div>
<button id="{$options->containerId}_submit" class="{$options->buttonClass}">{$options->buttonText}</button>

HTML;
        } elseif ($data->integrationMode === 'lightbox') {
            $uiHtml = <<<HTML
<button id="{$options->containerId}_btn" class="{$options->buttonClass}">{$options->buttonText}</button>

HTML;
        }

        return $uiHtml . $this->renderJs($data, $options);
    }

    /**
     * Renders only the `<script>` tags required to load the SDK and initialize the payment handler.
     *
     * This method is ideal for platforms that build their own UI but need the standardized
     * initialization logic to ensure handlers are correctly registered in the global registry.
     *
     * @param PaymentIntegrationData $data The payment integration metadata.
     * @param RenderOptions|null $options The options for rendering the payment block.
     * @return string The generated JavaScript/Script tags.
     */
    public function renderJs(PaymentIntegrationData $data, ?RenderOptions $options = null): string
    {
        $options = $options ?? new RenderOptions();
        $nonceAttr = $options->nonce ? ' nonce="' . htmlspecialchars($options->nonce, ENT_QUOTES, 'UTF-8') . '"' : '';

        $html = <<<HTML
<script src="{$data->javascriptUrl}"{$nonceAttr}></script>
<script{$nonceAttr}>
    window.__weareplanetHandlers = window.__weareplanetHandlers || {};
HTML;

        if ($data->integrationMode === 'iframe') {
            $html .= <<<HTML

    var handler = window.IframeCheckoutHandler({$data->paymentMethodConfigurationId});
    window.__weareplanetHandlers['{$data->paymentMethodConfigurationId}'] = handler;
    handler.setValidationCallback(function(validationResult) {
        var errorContainer = document.getElementById("{$options->containerId}_errors");
        if (errorContainer) {
            errorContainer.innerText = "";
            if (validationResult.success) {
                handler.submit();
            } else {
                errorContainer.innerText = validationResult.errors ? validationResult.errors.join("\\n") : "{$options->fallbackErrorMessage}";
            }
        }
    });
    handler.create("{$options->containerId}");
    var submitBtn = document.getElementById("{$options->containerId}_submit");
    if (submitBtn) {
        submitBtn.addEventListener("click", function() { handler.validate(); });
    }
</script>
HTML;
        } elseif ($data->integrationMode === 'lightbox') {
            $html .= <<<HTML

    window.__weareplanetHandlers['{$data->paymentMethodConfigurationId}'] = window.LightboxCheckoutHandler;
    var submitBtn = document.getElementById("{$options->containerId}_btn");
    if (submitBtn) {
        submitBtn.addEventListener("click", function() {
            window.LightboxCheckoutHandler.startPayment({$data->paymentMethodConfigurationId}, function(error) { alert(error); });
        });
    }
</script>
HTML;
        }

        return $html;
    }
}
