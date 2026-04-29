<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Render;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Render\IntegratedPaymentRenderService;
use WeArePlanet\PluginCore\Render\PaymentIntegrationData;
use WeArePlanet\PluginCore\Render\RenderOptions;

/**
 * Unit tests for the IntegratedPaymentRenderService.
 *
 * This test suite ensures that the service correctly generates the raw metadata,
 * JavaScript-only tags, and full HTML blocks required for various integration scenarios.
 */
class IntegratedPaymentRenderServiceTest extends TestCase
{
    /**
     * @var IntegratedPaymentRenderService
     */
    private IntegratedPaymentRenderService $service;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new IntegratedPaymentRenderService();
    }

    /**
     * Test getMetadata returns a correctly populated DTO.
     *
     * @return void
     */
    public function testGetMetadata(): void
    {
        $data = $this->service->getMetadata('http://example.com/js', 456, 'iframe');

        $this->assertInstanceOf(PaymentIntegrationData::class, $data);
        $this->assertEquals('http://example.com/js', $data->javascriptUrl);
        $this->assertEquals(456, $data->paymentMethodConfigurationId);
        $this->assertEquals('iframe', $data->integrationMode);
    }

    /**
     * Test rendering iframe HTML with custom options.
     *
     * @return void
     */
    public function testRenderHtmlIframe(): void
    {
        $data = new PaymentIntegrationData('http://example.com/js', 456, 'iframe');
        $options = new RenderOptions(
            containerId: 'custom-container',
            buttonText: 'Jetzt bezahlen',
            buttonClass: 'btn-primary',
            errorContainerClass: 'alert-danger',
            fallbackErrorMessage: 'Validierung fehlgeschlagen.',
        );

        $html = $this->service->renderHtml($data, $options);

        // Verify UI elements are present
        $this->assertStringContainsString('id="custom-container"', $html);
        $this->assertStringContainsString('class="btn-primary"', $html);
        $this->assertStringContainsString('class="alert-danger"', $html);
        $this->assertStringContainsString('Jetzt bezahlen', $html);

        // Verify JS logic is present
        $this->assertStringContainsString('window.IframeCheckoutHandler(456)', $html);
        $this->assertStringContainsString('window.__weareplanetHandlers = window.__weareplanetHandlers || {};', $html);
        $this->assertStringContainsString("window.__weareplanetHandlers['456'] = handler;", $html);

        // Verify no inline CSS
        $this->assertStringNotContainsString('style=', $html);
    }

    /**
     * Test rendering lightbox HTML.
     *
     * @return void
     */
    public function testRenderHtmlLightbox(): void
    {
        $data = new PaymentIntegrationData('http://example.com/js', 789, 'lightbox');
        $html = $this->service->renderHtml($data);

        // Verify button and JS
        $this->assertStringContainsString('id="payment-form_btn"', $html);
        $this->assertStringContainsString('Pay Now', $html);
        $this->assertStringContainsString('window.LightboxCheckoutHandler.startPayment(789', $html);
        $this->assertStringContainsString('window.__weareplanetHandlers = window.__weareplanetHandlers || {};', $html);
        $this->assertStringContainsString("window.__weareplanetHandlers['789'] = window.LightboxCheckoutHandler;", $html);
    }

    /**
     * Test rendering JS-only output with CSP nonce support.
     *
     * @return void
     */
    public function testRenderJsWithNonce(): void
    {
        $data = new PaymentIntegrationData('http://example.com/js', 123, 'iframe');
        $options = new RenderOptions(nonce: 'test-nonce-12345');

        $js = $this->service->renderJs($data, $options);

        // Verify script tags and nonce attributes
        $this->assertStringContainsString('<script src="http://example.com/js" nonce="test-nonce-12345"></script>', $js);
        $this->assertStringContainsString('<script nonce="test-nonce-12345">', $js);
        $this->assertStringContainsString('window.__weareplanetHandlers', $js);

        // Assert absence of UI elements in JS-only output
        $this->assertStringNotContainsString('<div', $js);
        $this->assertStringNotContainsString('<button', $js);
    }
}
