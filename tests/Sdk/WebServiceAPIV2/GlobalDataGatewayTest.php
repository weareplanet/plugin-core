<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\GlobalData\Exception\GlobalDataException;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\GlobalDataGateway;
use WeArePlanet\Sdk\Model\CurrencyListResponse as SdkCurrencyListResponse;
use WeArePlanet\Sdk\Model\LabelDescriptor as SdkLabelDescriptor;
use WeArePlanet\Sdk\Model\LabelDescriptorGroup as SdkLabelDescriptorGroup;
use WeArePlanet\Sdk\Model\LabelDescriptorGroupSearchResponse as SdkLabelDescriptorGroupSearchResponse;
use WeArePlanet\Sdk\Model\LabelDescriptorSearchResponse as SdkLabelDescriptorSearchResponse;
use WeArePlanet\Sdk\Model\LanguageListResponse as SdkLanguageListResponse;
use WeArePlanet\Sdk\Model\PaymentConnector as SdkPaymentConnector;
use WeArePlanet\Sdk\Model\PaymentConnectorFeature as SdkPaymentConnectorFeature;
use WeArePlanet\Sdk\Model\PaymentConnectorSearchResponse as SdkPaymentConnectorSearchResponse;
use WeArePlanet\Sdk\Model\PaymentMethod as SdkPaymentMethod;
use WeArePlanet\Sdk\Model\PaymentProcessor as SdkPaymentProcessor;
use WeArePlanet\Sdk\Model\RestApiErrorResponse as SdkRestApiErrorResponse;
use WeArePlanet\Sdk\Model\RestCurrency as SdkRestCurrency;
use WeArePlanet\Sdk\Model\RestLanguage as SdkRestLanguage;
use WeArePlanet\Sdk\Service\CurrenciesService as SdkCurrenciesService;
use WeArePlanet\Sdk\Service\LabelDescriptorsService as SdkLabelDescriptorsService;
use WeArePlanet\Sdk\Service\LanguagesService as SdkLanguagesService;
use WeArePlanet\Sdk\Service\PaymentConnectorsService as SdkPaymentConnectorsService;

class GlobalDataGatewayTest extends TestCase
{
    private MockObject|SdkCurrenciesService $currenciesService;
    private GlobalDataGateway $gateway;
    private MockObject|SdkLabelDescriptorsService $labelDescriptorsService;
    private MockObject|SdkLanguagesService $languagesService;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkPaymentConnectorsService $paymentConnectorsService;
    private MockObject|SdkProvider $sdkProvider;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->currenciesService = $this->createMock(SdkCurrenciesService::class);
        $this->languagesService = $this->createMock(SdkLanguagesService::class);
        $this->paymentConnectorsService = $this->createMock(SdkPaymentConnectorsService::class);
        $this->labelDescriptorsService = $this->createMock(SdkLabelDescriptorsService::class);

        $this->sdkProvider->method('getService')
            ->willReturnMap([
                [SdkCurrenciesService::class, $this->currenciesService],
                [SdkLanguagesService::class, $this->languagesService],
                [SdkPaymentConnectorsService::class, $this->paymentConnectorsService],
                [SdkLabelDescriptorsService::class, $this->labelDescriptorsService],
            ]);

        $this->gateway = new GlobalDataGateway(
            $this->sdkProvider,
            $this->logger,
        );
    }

    // ---------------------------------------------------------------------
    // Failure handling, uniform across all five operations
    // ---------------------------------------------------------------------

    /**
     * Maps each domain method to the SDK service property and SDK method it drives.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function operationProvider(): array
    {
        return [
            'currencies' => ['currenciesService', 'getCurrencies', 'getCurrencies'],
            'languages' => ['languagesService', 'getLanguages', 'getLanguages'],
            'payment connectors' => [
                'paymentConnectorsService',
                'getPaymentConnectorsSearch',
                'getPaymentConnectors',
            ],
            'label descriptors' => ['labelDescriptorsService', 'getLabelDescriptorsSearch', 'getLabelDescriptors'],
            'label descriptor groups' => [
                'labelDescriptorsService',
                'getLabelDescriptorsGroupsSearch',
                'getLabelDescriptorGroups',
            ],
        ];
    }

    #[DataProvider('operationProvider')]
    public function testEveryOperationLogsStructuredContextAndWrapsSdkFailures(
        string $sdkService,
        string $sdkMethod,
        string $method,
    ): void {
        $sdkException = new \Exception('SDK unavailable');
        $this->{$sdkService}->method($sdkMethod)->willThrowException($sdkException);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('failed'),
                $this->callback(function (array $context) use ($sdkException): bool {
                    $this->assertSame('SDK unavailable', $context['errorMessage']);
                    $this->assertSame($sdkException, $context['exception']);
                    $this->assertArrayHasKey('operation', $context);

                    return true;
                }),
            );

        $this->expectException(GlobalDataException::class);
        $this->gateway->{$method}();
    }

    #[DataProvider('operationProvider')]
    public function testEveryWrappedFailureKeepsTheSdkExceptionAsPrevious(
        string $sdkService,
        string $sdkMethod,
        string $method,
    ): void {
        $sdkException = new \Exception('SDK unavailable');
        $this->{$sdkService}->method($sdkMethod)->willThrowException($sdkException);

        try {
            $this->gateway->{$method}();
            $this->fail('Expected a GlobalDataException.');
        } catch (GlobalDataException $e) {
            $this->assertSame($sdkException, $e->getPrevious());
        }
    }

    /**
     * This SDK answers some non-2xx replies with an error model instead of throwing.
     * WebServiceAPIV1 has no equivalent model, hence no counterpart test there.
     */
    #[DataProvider('operationProvider')]
    public function testEveryOperationTurnsAnErrorResponseIntoAFailureCarryingItsDetails(
        string $sdkService,
        string $sdkMethod,
        string $method,
    ): void {
        $errorResponse = new SdkRestApiErrorResponse();
        $errorResponse->setMessage('Reference data is temporarily unavailable.');
        $errorResponse->setCode('SERVICE_UNAVAILABLE');

        $this->{$sdkService}->method($sdkMethod)->willReturn($errorResponse);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('unexpected response'),
                $this->callback(function (array $context): bool {
                    $this->assertSame('Reference data is temporarily unavailable.', $context['errorMessage']);
                    $this->assertSame('SERVICE_UNAVAILABLE', $context['errorCode']);

                    return true;
                }),
            );

        $this->expectException(GlobalDataException::class);
        $this->gateway->{$method}();
    }

    // ---------------------------------------------------------------------
    // Unpaginated endpoints: currencies and languages
    // ---------------------------------------------------------------------

    public function testGetCurrenciesCallsTheSdkWithNoParametersAndMapsEveryCurrency(): void
    {
        $response = new SdkCurrencyListResponse();
        $response->setData([
            $this->makeSdkCurrency('CHF', 2, 'Swiss Franc', 756),
            $this->makeSdkCurrency('JPY', 0, 'Japanese Yen', 392),
        ]);

        $this->currenciesService->expects($this->once())
            ->method('getCurrencies')
            ->with()
            ->willReturn($response);

        $collection = $this->gateway->getCurrencies();

        $this->assertCount(2, $collection);
        $chf = $collection->findByCurrencyCode('CHF');
        $this->assertNotNull($chf);
        $this->assertSame(2, $chf->fractionDigits);
        $this->assertSame('Swiss Franc', $chf->name);
        $this->assertSame(756, $chf->numericCode);
    }

    public function testGetLanguagesCallsTheSdkWithNoParametersAndResolvesThePrimaryVariant(): void
    {
        $response = new SdkLanguageListResponse();
        $response->setData([
            $this->makeSdkLanguage('en', 'en-GB', 'eng', 'English', 'GB', 'n != 1', false),
            $this->makeSdkLanguage('en', 'en-US', 'eng', 'English', 'US', 'n != 1', true),
        ]);

        $this->languagesService->expects($this->once())
            ->method('getLanguages')
            ->with()
            ->willReturn($response);

        $collection = $this->gateway->getLanguages();

        $this->assertCount(2, $collection);
        $primary = $collection->findPrimary('en');
        $this->assertNotNull($primary);
        $this->assertSame('en-US', $primary->ietfCode);
        $this->assertSame('US', $primary->countryCode);
        $this->assertSame('n != 1', $primary->pluralExpression);
    }

    public function testTheUnpaginatedEndpointsReturnAnEmptyCollectionWhenTheApiReportsNothing(): void
    {
        $currencies = new SdkCurrencyListResponse();
        $currencies->setData([]);
        $languages = new SdkLanguageListResponse();
        $languages->setData([]);

        $this->currenciesService->method('getCurrencies')->willReturn($currencies);
        $this->languagesService->method('getLanguages')->willReturn($languages);

        $this->assertTrue($this->gateway->getCurrencies()->isEmpty());
        $this->assertTrue($this->gateway->getLanguages()->isEmpty());
    }

    // ---------------------------------------------------------------------
    // Paginated endpoints: connectors, descriptors, groups.
    // These must page transparently so callers see complete collections, matching
    // WebServiceAPIV1's single all() call.
    // ---------------------------------------------------------------------

    public function testGetPaymentConnectorsRequestsTheFirstPageWithTheExpectedArguments(): void
    {
        $this->paymentConnectorsService->expects($this->once())
            ->method('getPaymentConnectorsSearch')
            ->with(null, 100, 0, null, null)
            ->willReturn($this->makeConnectorPage([$this->makeSdkConnector()], false));

        $this->gateway->getPaymentConnectors();
    }

    public function testGetPaymentConnectorsPagesThroughResultsAndCombinesThem(): void
    {
        $second = $this->makeSdkConnector();
        $second->setId(42);

        $this->paymentConnectorsService->expects($this->exactly(2))
            ->method('getPaymentConnectorsSearch')
            ->willReturnOnConsecutiveCalls(
                $this->makeConnectorPage([$this->makeSdkConnector()], true),
                $this->makeConnectorPage([$second], false),
            );

        $collection = $this->gateway->getPaymentConnectors();

        $this->assertCount(2, $collection);
        $this->assertNotNull($collection->findById(31));
        $this->assertNotNull($collection->findById(42));
    }

    public function testGetPaymentConnectorsMapsEveryConnector(): void
    {
        $this->paymentConnectorsService->method('getPaymentConnectorsSearch')
            ->willReturn($this->makeConnectorPage([$this->makeSdkConnector()], false));

        $connector = $this->gateway->getPaymentConnectors()->findById(31);

        $this->assertNotNull($connector);
        $this->assertSame('Visa Acquiring', $connector->name->localize('en-US'));
        // Normalized to IDs on every API version, whatever the payload carries.
        $this->assertSame(88, $connector->paymentMethodId);
        $this->assertSame(12, $connector->processorId);
        $this->assertSame([31], $connector->supportedFeatureIds);
        $this->assertSame('MERCHANT', $connector->primaryRiskTaker);
        $this->assertSame(['CHF', 'EUR'], $connector->supportedCurrencies);
        $this->assertSame(['VIRTUAL_PRESENT'], $connector->supportedCustomersPresences);
        $this->assertFalse($connector->deprecated);
    }

    public function testGetLabelDescriptorsRequestsTheFirstPageWithTheExpectedArguments(): void
    {
        $this->labelDescriptorsService->expects($this->once())
            ->method('getLabelDescriptorsSearch')
            ->with(null, 100, 0, null, null)
            ->willReturn($this->makeDescriptorPage([$this->makeSdkDescriptor(1001)], false));

        $this->gateway->getLabelDescriptors();
    }

    public function testGetLabelDescriptorsPagesThroughResultsAndCombinesThem(): void
    {
        $this->labelDescriptorsService->expects($this->exactly(2))
            ->method('getLabelDescriptorsSearch')
            ->willReturnOnConsecutiveCalls(
                $this->makeDescriptorPage([$this->makeSdkDescriptor(1001)], true),
                $this->makeDescriptorPage([$this->makeSdkDescriptor(1002)], false),
            );

        $collection = $this->gateway->getLabelDescriptors();

        $this->assertCount(2, $collection);
        $this->assertNotNull($collection->findById(1001));
        $this->assertNotNull($collection->findById(1002));
    }

    public function testGetLabelDescriptorsMapsEveryDescriptor(): void
    {
        $this->labelDescriptorsService->method('getLabelDescriptorsSearch')
            ->willReturn($this->makeDescriptorPage([$this->makeSdkDescriptor(1001)], false));

        $descriptor = $this->gateway->getLabelDescriptors()->findById(1001);

        $this->assertNotNull($descriptor);
        $this->assertSame('Card Brand', $descriptor->name->localize('en-US'));
        // Normalized to an ID on every API version, whatever the payload carries.
        $this->assertSame(4, $descriptor->groupId);
        $this->assertSame(10, $descriptor->weight);
        $this->assertSame('HUMAN', $descriptor->category);
        $this->assertSame(2, $descriptor->type);
    }

    public function testGetLabelDescriptorGroupsRequestsTheFirstPageWithTheExpectedArguments(): void
    {
        $this->labelDescriptorsService->expects($this->once())
            ->method('getLabelDescriptorsGroupsSearch')
            ->with(null, 100, 0, null, null)
            ->willReturn($this->makeGroupPage([$this->makeSdkGroup(4)], false));

        $this->gateway->getLabelDescriptorGroups();
    }

    public function testGetLabelDescriptorGroupsPagesThroughResultsAndCombinesThem(): void
    {
        $this->labelDescriptorsService->expects($this->exactly(2))
            ->method('getLabelDescriptorsGroupsSearch')
            ->willReturnOnConsecutiveCalls(
                $this->makeGroupPage([$this->makeSdkGroup(4)], true),
                $this->makeGroupPage([$this->makeSdkGroup(7)], false),
            );

        $collection = $this->gateway->getLabelDescriptorGroups();

        $this->assertCount(2, $collection);
        $this->assertNotNull($collection->findById(4));
        $this->assertNotNull($collection->findById(7));
    }

    public function testGetLabelDescriptorGroupsMapsEveryGroup(): void
    {
        $this->labelDescriptorsService->method('getLabelDescriptorsGroupsSearch')
            ->willReturn($this->makeGroupPage([$this->makeSdkGroup(4)], false));

        $group = $this->gateway->getLabelDescriptorGroups()->findById(4);

        $this->assertNotNull($group);
        $this->assertSame('Card', $group->name->localize('en-US'));
        $this->assertSame(10, $group->weight);
    }

    public function testThePaginatedEndpointsReturnAnEmptyCollectionWhenTheApiReportsNothing(): void
    {
        $this->paymentConnectorsService->method('getPaymentConnectorsSearch')
            ->willReturn($this->makeConnectorPage([], false));
        $this->labelDescriptorsService->method('getLabelDescriptorsSearch')
            ->willReturn($this->makeDescriptorPage([], false));
        $this->labelDescriptorsService->method('getLabelDescriptorsGroupsSearch')
            ->willReturn($this->makeGroupPage([], false));

        $this->assertTrue($this->gateway->getPaymentConnectors()->isEmpty());
        $this->assertTrue($this->gateway->getLabelDescriptors()->isEmpty());
        $this->assertTrue($this->gateway->getLabelDescriptorGroups()->isEmpty());
    }

    public function testAMalformedEntryIsSkippedAndLoggedWithoutLosingTheRest(): void
    {
        // Built via the constructor's data array rather than setData(): the point of
        // this test is a payload entry of the wrong type, which the typed setter would
        // refuse to accept even though the API can send exactly that.
        $response = new SdkCurrencyListResponse([
            'data' => [
                new \stdClass(),
                $this->makeSdkCurrency('CHF', 2, 'Swiss Franc', 756),
            ],
        ]);

        $this->currenciesService->method('getCurrencies')->willReturn($response);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Skipping a global data entry'),
                $this->callback(function (array $context): bool {
                    $this->assertArrayHasKey('entryType', $context);
                    $this->assertArrayHasKey('operation', $context);

                    return true;
                }),
            );

        $collection = $this->gateway->getCurrencies();

        $this->assertCount(1, $collection);
        $this->assertNotNull($collection->findByCurrencyCode('CHF'));
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    /**
     * @param list<SdkPaymentConnector> $connectors
     */
    private function makeConnectorPage(array $connectors, bool $hasMore): SdkPaymentConnectorSearchResponse
    {
        $response = new SdkPaymentConnectorSearchResponse();
        $response->setData($connectors);
        $response->setHasMore($hasMore);

        return $response;
    }

    /**
     * @param list<SdkLabelDescriptor> $descriptors
     */
    private function makeDescriptorPage(array $descriptors, bool $hasMore): SdkLabelDescriptorSearchResponse
    {
        $response = new SdkLabelDescriptorSearchResponse();
        $response->setData($descriptors);
        $response->setHasMore($hasMore);

        return $response;
    }

    /**
     * @param list<SdkLabelDescriptorGroup> $groups
     */
    private function makeGroupPage(array $groups, bool $hasMore): SdkLabelDescriptorGroupSearchResponse
    {
        $response = new SdkLabelDescriptorGroupSearchResponse();
        $response->setData($groups);
        $response->setHasMore($hasMore);

        return $response;
    }

    private function makeSdkConnector(): SdkPaymentConnector
    {
        // This API embeds the payment method, processor and features as whole entities.
        $sdkPaymentMethod = new SdkPaymentMethod();
        $sdkPaymentMethod->setId(88);

        $sdkProcessor = new SdkPaymentProcessor();
        $sdkProcessor->setId(12);

        $sdkFeature = new SdkPaymentConnectorFeature();
        $sdkFeature->setId(31);

        // 'supported_customers_presences' is set via the constructor's data array rather
        // than the fluent setter: the SDK documents it as an array of a pseudo-enum
        // class, but the values it actually sends and accepts are that class's bare
        // constant strings (e.g. CustomersPresence::VIRTUAL_PRESENT === 'VIRTUAL_PRESENT').
        // The constructor assigns the array as-is with no per-field type checking, so it
        // reflects that reality instead of the inaccurate docblock.
        $sdkConnector = new SdkPaymentConnector(['supported_customers_presences' => ['VIRTUAL_PRESENT']]);
        $sdkConnector->setId(31);
        $sdkConnector->setName(['en-US' => 'Visa Acquiring']);
        $sdkConnector->setPaymentMethod($sdkPaymentMethod);
        $sdkConnector->setProcessor($sdkProcessor);
        $sdkConnector->setPrimaryRiskTaker('MERCHANT');
        $sdkConnector->setSupportedCurrencies(['CHF', 'EUR']);
        $sdkConnector->setSupportedFeatures([$sdkFeature]);
        $sdkConnector->setDeprecated(false);

        return $sdkConnector;
    }

    private function makeSdkCurrency(
        string $currencyCode,
        int $fractionDigits,
        string $name,
        int $numericCode,
    ): SdkRestCurrency {
        $sdkCurrency = new SdkRestCurrency();
        $sdkCurrency->setCurrencyCode($currencyCode);
        $sdkCurrency->setFractionDigits($fractionDigits);
        $sdkCurrency->setName($name);
        $sdkCurrency->setNumericCode($numericCode);

        return $sdkCurrency;
    }

    private function makeSdkDescriptor(int $id): SdkLabelDescriptor
    {
        // This API embeds the group as a whole entity rather than reporting a bare ID.
        $sdkGroup = new SdkLabelDescriptorGroup();
        $sdkGroup->setId(4);

        // 'category' is set via the constructor's data array rather than the fluent
        // setter: the SDK documents it as a pseudo-enum class, but the value it actually
        // sends and accepts is that class's bare constant string (e.g.
        // LabelDescriptorCategory::HUMAN === 'HUMAN').
        $sdkDescriptor = new SdkLabelDescriptor(['category' => 'HUMAN']);
        $sdkDescriptor->setId($id);
        $sdkDescriptor->setName(['en-US' => 'Card Brand']);
        $sdkDescriptor->setGroup($sdkGroup);
        $sdkDescriptor->setWeight(10);
        $sdkDescriptor->setType(2);

        return $sdkDescriptor;
    }

    private function makeSdkGroup(int $id): SdkLabelDescriptorGroup
    {
        $sdkGroup = new SdkLabelDescriptorGroup();
        $sdkGroup->setId($id);
        $sdkGroup->setName(['en-US' => 'Card']);
        $sdkGroup->setWeight(10);

        return $sdkGroup;
    }

    private function makeSdkLanguage(
        string $iso2Code,
        string $ietfCode,
        string $iso3Code,
        string $name,
        ?string $countryCode,
        // This SDK's setter rejects null for plural_expression despite the getter being
        // typed nullable, so callers always supply a real value.
        ?string $pluralExpression,
        bool $primaryOfGroup,
    ): SdkRestLanguage {
        $sdkLanguage = new SdkRestLanguage();
        $sdkLanguage->setIso2Code($iso2Code);
        $sdkLanguage->setIetfCode($ietfCode);
        $sdkLanguage->setIso3Code($iso3Code);
        $sdkLanguage->setName($name);
        $sdkLanguage->setCountryCode($countryCode);
        $sdkLanguage->setPluralExpression($pluralExpression);
        $sdkLanguage->setPrimaryOfGroup($primaryOfGroup);

        return $sdkLanguage;
    }

    public function testSuccessfulReadIsLoggedAtDebugAndNeverAtInfo(): void
    {
        // These are routine, high-frequency reads of static reference data. A
        // confirmation that one worked carries no business meaning, so it must not
        // compete at info level with records that describe actual state changes.
        $response = new SdkCurrencyListResponse();
        $response->setData([$this->makeSdkCurrency('CHF', 2, 'Swiss Franc', 756)]);

        $this->currenciesService->method('getCurrencies')->willReturn($response);

        $this->logger->expects($this->never())->method('info');

        $debugRecords = [];
        $this->logger->method('debug')
            ->willReturnCallback(function (string $message, array $context = []) use (&$debugRecords): void {
                $debugRecords[] = [$message, $context];
            });

        $this->gateway->getCurrencies();

        $succeeded = array_values(array_filter(
            $debugRecords,
            static fn (array $record): bool => str_contains($record[0], 'succeeded'),
        ));

        $this->assertCount(1, $succeeded, 'Expected the success confirmation at debug level.');
        $this->assertSame(1, $succeeded[0][1]['count']);
        $this->assertArrayHasKey('operation', $succeeded[0][1]);
    }
}
