<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV1;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Webhook\Exception\WebhookSignatureValidationException;
use WeArePlanet\PluginCore\Webhook\WebhookSignatureGatewayInterface;
use WeArePlanet\Sdk\Service\WebhookEncryptionService as SdkWebhookEncryptionService;

/**
 * Class WebhookSignatureGateway
 *
 * Implementation of the WebhookSignatureGatewayInterface using the WeArePlanet SDK.
 */
#[LogContext(domain: 'webhook')]
class WebhookSignatureGateway implements WebhookSignatureGatewayInterface
{
    use DomainLoggerTrait;
    /**
     * @var SdkWebhookEncryptionService
     */
    private SdkWebhookEncryptionService $webhookEncryptionService;

    /**
     * WebhookSignatureGateway constructor.
     *
     * @param SdkProvider $sdkProvider
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
        $this->webhookEncryptionService = $this->sdkProvider->getService(SdkWebhookEncryptionService::class);
    }

    /**
     * Validates the payload signature.
     *
     * @param string $signatureHeader The signature string from the request headers.
     * @param string $payload The raw request body content.
     * @return bool True if the signature is valid, false otherwise.
     * @throws WebhookSignatureValidationException If signature validation fails due to key/API errors.
     */
    public function validate(string $signatureHeader, string $payload): bool
    {
        try {
            return (bool)$this->webhookEncryptionService->isContentValid($signatureHeader, $payload);
        } catch (\Throwable $e) {
            // TODO: Include spaceId and transactionId in log context when available
            $this->logger->error(
                'Webhook signature validation failed: {errorMessage}',
                [
                    'errorMessage' => $e->getMessage(),
                    'exception' => $e,
                ],
            );
            throw new WebhookSignatureValidationException(
                "Webhook signature validation failed: " . $e->getMessage(),
                new LocalizedString("Webhook signature validation failed."),
                $e,
            );
        }
    }
}
