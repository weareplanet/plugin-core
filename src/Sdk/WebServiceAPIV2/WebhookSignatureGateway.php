<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Webhook\WebhookSignatureGatewayInterface;
use WeArePlanet\Sdk\Service\WebhookEncryptionKeysService as SdkWebhookEncryptionKeysService;

/**
 * Class WebhookSignatureGateway
 *
 * Implementation of the WebhookSignatureGatewayInterface using the WeArePlanet SDK V2.
 */
class WebhookSignatureGateway implements WebhookSignatureGatewayInterface
{
    private SdkWebhookEncryptionKeysService $webhookEncryptionKeysService;

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
        $this->webhookEncryptionKeysService = $this->sdkProvider->getService(SdkWebhookEncryptionKeysService::class);
    }

    /**
     * @inheritDoc
     */
    public function validate(string $signatureHeader, string $payload): bool
    {
        try {
            return $this->webhookEncryptionKeysService->isContentValid($signatureHeader, $payload);
        } catch (\Exception $e) {
            $this->logger->warning("Webhook signature validation failed: " . $e->getMessage());
            return false;
        }
    }
}
