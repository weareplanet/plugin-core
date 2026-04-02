<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Http\Request;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\TransactionGatewayInterface;
use WeArePlanet\Sdk\Service\WebhookEncryptionKeysService;

/**
 * Default implementation for fetching the remote state of an entity.
 */
class DefaultStateFetcher implements StateFetcherInterface
{
    /**
     * @param SdkProvider $sdkProvider
     * @param Settings $settings
     * @param TransactionGatewayInterface $transactionGateway
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        private readonly Settings $settings,
        private readonly TransactionGatewayInterface $transactionGateway,
    ) {
    }

    /**
     * @param Request $request
     * @param int $entityId
     * @return string
     * @throws \Exception
     */
    public function fetchState(Request $request, int $entityId): string
    {
        $signatureHeader = $request->getHeader('x-signature');

        if ($signatureHeader) {
            /** @var WebhookEncryptionKeysService $encryptionService */
            $encryptionService = $this->sdkProvider->getService(WebhookEncryptionKeysService::class);

            // New way, signed state from webhook.
            if ($encryptionService->isContentValid($signatureHeader, $request->getRawBody())) {
                $body = $request->body;
                if (empty($body['state'])) {
                    throw new \Exception("Webhook payload is signed but missing 'state' field.");
                }
                return (string) $body['state'];
            }

            throw new \Exception("Invalid webhook signature.");
        }

        // Legacy way, fetch state from Portal API (extra request(s)).
        //TODO: Consider removing support for this way.
        //TODO: It may not be always transaction, but other entity. It can be added by defining
        // an interface for getting a state by id. For now, we assume transaction only.
        $maxRetries = 10;
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                /** @var Transaction $transaction */
                $transaction = $this->transactionGateway->get($this->settings->getSpaceId(), $entityId);
                return $transaction->state->value;
            } catch (\Exception $e) {
                if ($i === $maxRetries - 1) {
                    throw $e;
                }
                sleep($i * 2);
            }
        }

        throw new \Exception("Failed to fetch state for entity $entityId after $maxRetries retries.");
    }
}
