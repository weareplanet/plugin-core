<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Http\Request;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Webhook\Exception\CommandException;
use WeArePlanet\Sdk\Service\DeliveryIndicationsService;
use WeArePlanet\Sdk\Service\ManualTasksService;
use WeArePlanet\Sdk\Service\RefundsService;
use WeArePlanet\Sdk\Service\TokensService;
use WeArePlanet\Sdk\Service\TransactionCompletionsService;
use WeArePlanet\Sdk\Service\TransactionInvoicesService;
use WeArePlanet\Sdk\Service\TransactionsService;
use WeArePlanet\Sdk\Service\TransactionVoidsService;
use WeArePlanet\Sdk\Service\WebhookEncryptionKeysService;

/**
 * Default implementation for fetching the remote state of an entity.
 */
class DefaultStateFetcher implements StateFetcherInterface
{
    /**
     * Constructs the DefaultStateFetcher.
     *
     * @param SdkProvider $sdkProvider The SDK provider.
     * @param Settings $settings The plugin settings.
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        private readonly Settings $settings,
    ) {
    }

    /**
     * Fetches the state for the given webhook request.
     *
     * @param Request $request The incoming request object.
     * @param int $entityId The ID of the entity.
     * @return string The resolved state.
     * @throws \Exception If the state cannot be fetched or validation fails.
     */
    public function fetchState(Request $request, int $entityId): string
    {
        // Resolve signature header to determine if state is securely signed.
        $signatureHeader = $request->getHeader('x-signature', );

        if ($signatureHeader) {
            // Retrieve encryption service to validate the payload integrity.
            /** @var WebhookEncryptionKeysService $encryptionService */
            $encryptionService = $this->sdkProvider->getService(WebhookEncryptionKeysService::class, );

            // Validating the signed body allows resolving the state without external API calls.
            if ($encryptionService->isContentValid($signatureHeader, $request->getRawBody(), )) {
                $body = $request->body;
                if (empty($body['state'])) {
                    throw new CommandException(
                        "Webhook payload is signed but missing 'state' field.",
                        new LocalizedString("Webhook payload is signed but missing state."),
                    );
                }
                return (string) $body['state'];
            }

            throw new CommandException(
                "Invalid webhook signature: validation check failed.",
                new LocalizedString("Invalid webhook signature."),
            );
        }

        // Without a signature, fall back to the legacy path which retrieves the state from the API.
        $body = $request->body;
        $technicalName = $body['listenerEntityTechnicalName'] ?? null;

        // The technical name of the entity must be present to select the correct SDK service.
        if (empty($technicalName)) {
            throw new CommandException(
                "Unsigned webhook payload missing 'listenerEntityTechnicalName'.",
                new LocalizedString("Webhook payload missing entity name."),
            );
        }

        // Resolve the service class before the retry loop to avoid retrying unsupported entities.
        $serviceClass = $this->getServiceClass((string) $technicalName, );

        // Retry the API request to handle transient network issues or race conditions.
        $maxRetries = 10;
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                return $this->fetchStateFromApi(
                    $serviceClass,
                    $entityId,
                );
            } catch (\Exception $e) {
                // On last retry, propagate the exception to fail the command.
                if ($i === $maxRetries - 1) {
                    throw $e;
                }
                sleep($i * 2, );
            }
        }

        throw new CommandException(
            "Failed to fetch state for entity '$technicalName' with ID $entityId after $maxRetries retries.",
            new LocalizedString('Failed to fetch state for entity after maximum retries.'),
        );
    }

    /**
     * Helper to dynamically fetch the remote state from the Portal API.
     *
     * @param class-string<object> $serviceClass The SDK service class name.
     * @param int $entityId The ID of the entity.
     * @return string The resolved remote state.
     */
    private function fetchStateFromApi(string $serviceClass, int $entityId): string
    {
        // Instantiate the service dynamically and perform the read operation.
        $service = $this->sdkProvider->getService($serviceClass, );

        if (method_exists($service, 'read', )) {
            $result = $service->read(
                $this->settings->getSpaceId(),
                $entityId,
            );
        } else {
            $methodName = match ($serviceClass) {
                DeliveryIndicationsService::class => 'getPaymentDeliveryIndicationsId',
                ManualTasksService::class => 'getManualTasksId',
                RefundsService::class => 'getPaymentRefundsId',
                TokensService::class => 'getPaymentTokensId',
                TransactionsService::class => 'getPaymentTransactionsId',
                TransactionCompletionsService::class => 'getPaymentTransactionsCompletionsId',
                TransactionInvoicesService::class => 'getPaymentTransactionsInvoicesId',
                TransactionVoidsService::class => 'getPaymentTransactionsVoidsId',
                default => 'read',
            };

            $result = $service->$methodName(
                $entityId,
                $this->settings->getSpaceId(),
            );
        }

        return (string) $result->getState();
    }

    /**
     * Maps the technical name of the entity to its corresponding SDK service class.
     *
     * @param string $technicalName The technical name of the entity.
     * @return string The SDK service class name.
     * @throws CommandException If the technical name is not supported.
     */
    private function getServiceClass(string $technicalName): string
    {
        return match ($technicalName) {
            'DeliveryIndication' => DeliveryIndicationsService::class,
            'ManualTask' => ManualTasksService::class,
            'Refund' => RefundsService::class,
            'Token' => TokensService::class,
            'Transaction' => TransactionsService::class,
            'TransactionCompletion' => TransactionCompletionsService::class,
            'TransactionInvoice' => TransactionInvoicesService::class,
            'TransactionVoid' => TransactionVoidsService::class,
            default => throw new CommandException(
                "Legacy state fetching not supported for entity: " . $technicalName,
                new LocalizedString('Legacy state fetching not supported for this entity type.'),
            ),
        };
    }
}
