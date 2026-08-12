<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV1;

use WeArePlanet\PluginCore\GlobalData\Currency\CurrencyCollection;
use WeArePlanet\PluginCore\GlobalData\Exception\GlobalDataException;
use WeArePlanet\PluginCore\GlobalData\GlobalDataGatewayInterface;
use WeArePlanet\PluginCore\GlobalData\LabelDescriptor\LabelDescriptorCollection;
use WeArePlanet\PluginCore\GlobalData\LabelDescriptorGroup\LabelDescriptorGroupCollection;
use WeArePlanet\PluginCore\GlobalData\Language\LanguageCollection;
use WeArePlanet\PluginCore\GlobalData\PaymentConnector\PaymentConnectorCollection;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\CurrencyMapperTrait;
use WeArePlanet\PluginCore\Sdk\LabelDescriptorGroupMapperTrait;
use WeArePlanet\PluginCore\Sdk\LabelDescriptorMapperTrait;
use WeArePlanet\PluginCore\Sdk\LanguageMapperTrait;
use WeArePlanet\PluginCore\Sdk\PaymentConnectorMapperTrait;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\Sdk\Model\LabelDescriptor as SdkLabelDescriptor;
use WeArePlanet\Sdk\Model\LabelDescriptorGroup as SdkLabelDescriptorGroup;
use WeArePlanet\Sdk\Model\PaymentConnector as SdkPaymentConnector;
use WeArePlanet\Sdk\Model\RestCurrency as SdkRestCurrency;
use WeArePlanet\Sdk\Model\RestLanguage as SdkRestLanguage;
use WeArePlanet\Sdk\Service\CurrencyService as SdkCurrencyService;
use WeArePlanet\Sdk\Service\LabelDescriptionGroupService as SdkLabelDescriptionGroupService;
use WeArePlanet\Sdk\Service\LabelDescriptionService as SdkLabelDescriptionService;
use WeArePlanet\Sdk\Service\LanguageService as SdkLanguageService;
use WeArePlanet\Sdk\Service\PaymentConnectorService as SdkPaymentConnectorService;

/**
 * Gateway for retrieving the WeArePlanet Portal's global reference data using the SDK.
 *
 * Every operation here maps to an unparameterised `all()` on its SDK service:
 * this data is global to the WeArePlanet Portal, so none of these calls is space-scoped and
 * none of them paginates. Unlike most other gateways in PluginCore, there is
 * therefore no per-call identifying context (no space ID, no entity ID) to
 * include in log records or exception messages — the operation name alone
 * identifies what was attempted.
 *
 * The five entity types live behind five separate SDK services on this API
 * version, so this class holds all five. Converting SDK models into domain
 * entities is the mapper traits' job; this class owns the calls, their
 * observability and their failure handling.
 */
#[LogContext(domain: 'global_data')]
class GlobalDataGateway implements GlobalDataGatewayInterface
{
    use CurrencyMapperTrait;
    use DomainLoggerTrait;
    use LabelDescriptorGroupMapperTrait;
    use LabelDescriptorMapperTrait;
    use LanguageMapperTrait;
    use PaymentConnectorMapperTrait;

    private SdkCurrencyService $currencyService;
    private SdkLabelDescriptionGroupService $labelDescriptorGroupService;
    private SdkLabelDescriptionService $labelDescriptorService;
    private SdkLanguageService $languageService;
    private SdkPaymentConnectorService $paymentConnectorService;

    /**
     * @param SdkProvider $sdkProvider The SDK provider.
     * @param LoggerInterface $logger The logger instance.
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
        $this->currencyService = $this->sdkProvider->getService(SdkCurrencyService::class);
        $this->languageService = $this->sdkProvider->getService(SdkLanguageService::class);
        $this->paymentConnectorService = $this->sdkProvider->getService(SdkPaymentConnectorService::class);
        $this->labelDescriptorService = $this->sdkProvider->getService(SdkLabelDescriptionService::class);
        $this->labelDescriptorGroupService = $this->sdkProvider->getService(SdkLabelDescriptionGroupService::class);
    }


    /**
     * @inheritDoc
     */
    public function getCurrencies(): CurrencyCollection
    {
        $operation = 'currency.all';
        $this->logger->debug('Calling global data operation.', ['operation' => $operation]);

        try {
            $response = $this->currencyService->all();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Global data operation failed.',
                ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e],
            );

            throw SdkProvider::wrapException(
                $e,
                GlobalDataException::class,
                $operation,
                [],
                'The payment configuration could not be loaded. Please try again later.',
            );
        }

        $results = $this->requireList($response, $operation);

        $currencies = [];
        foreach ($results as $sdkCurrency) {
            if (!$sdkCurrency instanceof SdkRestCurrency) {
                $this->skippedEntry($operation, $sdkCurrency);

                continue;
            }

            $currencies[] = $this->mapToCurrency($sdkCurrency);
        }

        $this->succeeded($operation, count($currencies));

        return new CurrencyCollection(...$currencies);
    }

    /**
     * @inheritDoc
     */
    public function getLabelDescriptorGroups(): LabelDescriptorGroupCollection
    {
        $operation = 'labelDescriptionGroup.all';
        $this->logger->debug('Calling global data operation.', ['operation' => $operation]);

        try {
            $response = $this->labelDescriptorGroupService->all();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Global data operation failed.',
                ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e],
            );

            throw SdkProvider::wrapException(
                $e,
                GlobalDataException::class,
                $operation,
                [],
                'The payment configuration could not be loaded. Please try again later.',
            );
        }

        $results = $this->requireList($response, $operation);

        $groups = [];
        foreach ($results as $sdkGroup) {
            if (!$sdkGroup instanceof SdkLabelDescriptorGroup) {
                $this->skippedEntry($operation, $sdkGroup);

                continue;
            }

            $groups[] = $this->mapToLabelDescriptorGroup($sdkGroup);
        }

        $this->succeeded($operation, count($groups));

        return new LabelDescriptorGroupCollection(...$groups);
    }

    /**
     * @inheritDoc
     */
    public function getLabelDescriptors(): LabelDescriptorCollection
    {
        $operation = 'labelDescription.all';
        $this->logger->debug('Calling global data operation.', ['operation' => $operation]);

        try {
            $response = $this->labelDescriptorService->all();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Global data operation failed.',
                ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e],
            );

            throw SdkProvider::wrapException(
                $e,
                GlobalDataException::class,
                $operation,
                [],
                'The payment configuration could not be loaded. Please try again later.',
            );
        }

        $results = $this->requireList($response, $operation);

        $descriptors = [];
        foreach ($results as $sdkDescriptor) {
            if (!$sdkDescriptor instanceof SdkLabelDescriptor) {
                $this->skippedEntry($operation, $sdkDescriptor);

                continue;
            }

            $descriptors[] = $this->mapToLabelDescriptor($sdkDescriptor);
        }

        $this->succeeded($operation, count($descriptors));

        return new LabelDescriptorCollection(...$descriptors);
    }

    /**
     * @inheritDoc
     */
    public function getLanguages(): LanguageCollection
    {
        $operation = 'language.all';
        $this->logger->debug('Calling global data operation.', ['operation' => $operation]);

        try {
            $response = $this->languageService->all();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Global data operation failed.',
                ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e],
            );

            throw SdkProvider::wrapException(
                $e,
                GlobalDataException::class,
                $operation,
                [],
                'The payment configuration could not be loaded. Please try again later.',
            );
        }

        $results = $this->requireList($response, $operation);

        $languages = [];
        foreach ($results as $sdkLanguage) {
            if (!$sdkLanguage instanceof SdkRestLanguage) {
                $this->skippedEntry($operation, $sdkLanguage);

                continue;
            }

            $languages[] = $this->mapToLanguage($sdkLanguage);
        }

        $this->succeeded($operation, count($languages));

        return new LanguageCollection(...$languages);
    }

    /**
     * @inheritDoc
     */
    public function getPaymentConnectors(): PaymentConnectorCollection
    {
        $operation = 'paymentConnector.all';
        $this->logger->debug('Calling global data operation.', ['operation' => $operation]);

        try {
            $response = $this->paymentConnectorService->all();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Global data operation failed.',
                ['operation' => $operation, 'errorMessage' => $e->getMessage(), 'exception' => $e],
            );

            throw SdkProvider::wrapException(
                $e,
                GlobalDataException::class,
                $operation,
                [],
                'The payment configuration could not be loaded. Please try again later.',
            );
        }

        $results = $this->requireList($response, $operation);

        $connectors = [];
        foreach ($results as $sdkConnector) {
            if (!$sdkConnector instanceof SdkPaymentConnector) {
                $this->skippedEntry($operation, $sdkConnector);

                continue;
            }

            $connectors[] = $this->mapToPaymentConnector($sdkConnector);
        }

        $this->succeeded($operation, count($connectors));

        return new PaymentConnectorCollection(...$connectors);
    }

    /**
     * Requires a raw SDK response to be a list of entities.
     *
     * @param mixed $result The raw SDK response.
     * @param string $operation The SDK operation name, for log context.
     * @return array<mixed> The response as an array, ready to be iterated.
     * @throws GlobalDataException If the response was not an array.
     */
    private function requireList(mixed $result, string $operation): array
    {
        if (!is_array($result)) {
            $this->logger->error(
                'Global data operation returned an unexpected response.',
                ['operation' => $operation, 'responseType' => get_debug_type($result)],
            );

            throw SdkProvider::unexpectedResponseException(
                GlobalDataException::class,
                $operation,
                // Global data is not space-scoped, so there is no identifying context.
                [],
                'The payment configuration could not be loaded. Please try again later.',
            );
        }

        return $result;
    }

    /**
     * Records that one entry of a list could not be interpreted.
     *
     * A malformed entry does not invalidate the rest of the list; the missing one is
     * simply absent from the collection, and this is the record of why.
     *
     * @param string $operation The SDK operation name, for log context.
     * @param mixed $entry The unusable entry.
     * @return void
     */
    private function skippedEntry(string $operation, mixed $entry): void
    {
        $this->logger->warning(
            'Skipping a global data entry that was not the expected type.',
            ['operation' => $operation, 'entryType' => get_debug_type($entry)],
        );
    }

    /**
     * Records a successful retrieval and how much it returned.
     *
     * Logged at debug, not info: these are routine, high-frequency reads of static
     * reference data, and a confirmation that one worked carries no business meaning
     * — unlike a state change, which does belong at info. Failures are unaffected and
     * still surface at error level from each operation's own catch block.
     *
     * @param string $operation The SDK operation name, for log context.
     * @param int $count How many entities were mapped.
     * @return void
     */
    private function succeeded(string $operation, int $count): void
    {
        $this->logger->debug(
            'Global data operation succeeded.',
            ['operation' => $operation, 'count' => $count],
        );
    }
}
