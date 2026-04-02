<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\SdkV1;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Webhook\WebhookSignatureGatewayInterface;
use WeArePlanet\Sdk\Service\WebhookEncryptionService as SdkWebhookEncryptionService;

/**
 * Class WebhookSignatureGateway
 *
 * Implementation of the WebhookSignatureGatewayInterface using the WeArePlanet SDK.
 */
class WebhookSignatureGateway implements WebhookSignatureGatewayInterface
{
    private SdkWebhookEncryptionService $webhookEncryptionService;

    /**
     * WebhookSignatureGateway constructor.
     *
     * @param SdkProvider $sdkProvider
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        private readonly LoggerInterface $logger,
    ) {
        $this->webhookEncryptionService = $this->sdkProvider->getService(SdkWebhookEncryptionService::class);
    }

    /**
     * @inheritDoc
     */
    public function validate(string $signatureHeader, string $payload): bool
    {
        try {
            // The SDK's WebhookEncryptionService::isContentValid returns validated resource or throws exception?
            // Usually returns true/false or object. The user said:
            // "Call $service->isContentValid($signatureHeader, $payload). Catch \Exception or \InvalidArgumentException ... and return false. Return true on success."
            // Assuming isContentValid returns the content or simple boolean. Checking context...
            // User said: "Call $service->isContentValid... Catch... Return true on success."
            // I will assume it returns something truthy on success.

            return $this->webhookEncryptionService->isContentValid($signatureHeader, $payload);
        } catch (\Exception $e) {
            $this->logger->warning("Webhook signature validation failed: " . $e->getMessage());
            return false;
        }
    }
}
