<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV1;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\GlobalData\Exception\GlobalDataException;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\GlobalDataGateway;
use WeArePlanet\PluginCore\SharedKernel\CacheInterface;
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

class GlobalDataGatewayTest extends TestCase
{
    private MockObject|SdkCurrencyService $currencyService;
    private GlobalDataGateway $gateway;
    private MockObject|SdkLabelDescriptionGroupService $labelDescriptorGroupService;
    private MockObject|SdkLabelDescriptionService $labelDescriptorService;
    private MockObject|SdkLanguageService $languageService;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkPaymentConnectorService $paymentConnectorService;
    private MockObject|SdkProvider $sdkProvider;

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    private function makeSdkConnector(): SdkPaymentConnector
    {
        // This API reports the payment method, processor and features as bare IDs.
        //
        // Built via the constructor's data array rather than the fluent setters: the
        // SDK documents supported_customers_presences as an array of a pseudo-enum
        // class, but the values it actually sends and accepts are that class's bare
        // constant strings (e.g. CustomersPresence::VIRTUAL_PRESENT === 'VIRTUAL_PRESENT').
        // The constructor assigns the array as-is with no per-field type checking, so it
        // reflects that reality instead of the inaccurate docblock.
        return new SdkPaymentConnector([
            'id' => 31,
            'name' => ['en-US' => 'Visa Acquiring'],
            'payment_method' => 88,
            'processor' => 12,
            'primary_risk_taker' => 'MERCHANT',
            'supported_currencies' => ['CHF', 'EUR'],
            'supported_customers_presences' => ['VIRTUAL_PRESENT'],
            'supported_features' => [31],
            'deprecated' => false,
        ]);
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

    /**
     * @param array<string, string> $name
     */
    private function makeSdkDescriptor(
        int $id,
        array $name,
        ?int $groupId,
        int $weight,
        string $category,
        ?int $type,
    ): SdkLabelDescriptor {
        // This API reports the group as a bare ID.
        $sdkDescriptor = new SdkLabelDescriptor();
        $sdkDescriptor->setId($id);
        $sdkDescriptor->setName($name);
        $sdkDescriptor->setGroup($groupId);
        $sdkDescriptor->setWeight($weight);
        $sdkDescriptor->setCategory($category);
        $sdkDescriptor->setType($type);

        return $sdkDescriptor;
    }

    /**
     * @param array<string, string> $name
     */
    private function makeSdkGroup(int $id, array $name, int $weight): SdkLabelDescriptorGroup
    {
        $sdkGroup = new SdkLabelDescriptorGroup();
        $sdkGroup->setId($id);
        $sdkGroup->setName($name);
        $sdkGroup->setWeight($weight);

        return $sdkGroup;
    }

    private function makeSdkLanguage(
        string $iso2Code,
        string $ietfCode,
        string $iso3Code,
        string $name,
        ?string $countryCode,
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

    // ---------------------------------------------------------------------
    // Every operation calls an unparameterised all() — this API is not paginated
    // and not space-scoped.
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function operationProvider(): array
    {
        return [
            'currencies' => ['currencyService', 'getCurrencies'],
            'languages' => ['languageService', 'getLanguages'],
            'payment connectors' => ['paymentConnectorService', 'getPaymentConnectors'],
            'label descriptors' => ['labelDescriptorService', 'getLabelDescriptors'],
            'label descriptor groups' => ['labelDescriptorGroupService', 'getLabelDescriptorGroups'],
        ];
    }

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->currencyService = $this->createMock(SdkCurrencyService::class);
        $this->languageService = $this->createMock(SdkLanguageService::class);
        $this->paymentConnectorService = $this->createMock(SdkPaymentConnectorService::class);
        $this->labelDescriptorService = $this->createMock(SdkLabelDescriptionService::class);
        $this->labelDescriptorGroupService = $this->createMock(SdkLabelDescriptionGroupService::class);

        $this->sdkProvider->method('getService')
            ->willReturnMap([
                [SdkCurrencyService::class, $this->currencyService],
                [SdkLanguageService::class, $this->languageService],
                [SdkPaymentConnectorService::class, $this->paymentConnectorService],
                [SdkLabelDescriptionService::class, $this->labelDescriptorService],
                [SdkLabelDescriptionGroupService::class, $this->labelDescriptorGroupService],
            ]);

        $this->gateway = new GlobalDataGateway(
            $this->sdkProvider,
            $this->logger,
        );
    }

    public function testAMalformedEntryIsSkippedAndLoggedWithoutLosingTheRest(): void
    {
        $this->currencyService->method('all')->willReturn([
            new \stdClass(),
            $this->makeSdkCurrency('CHF', 2, 'Swiss Franc', 756),
        ]);

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

    public function testCacheKeysAreUniquelyPrefixedWithWhitelabelmachinename(): void
    {
        $reflection = new \ReflectionClass(GlobalDataGateway::class);
        $descriptorsKey = $reflection->getConstant('CACHE_KEY_LABEL_DESCRIPTORS');
        $groupsKey = $reflection->getConstant('CACHE_KEY_LABEL_DESCRIPTOR_GROUPS');

        $this->assertStringStartsWith('weareplanet:', $descriptorsKey);
        $this->assertStringStartsWith('weareplanet:', $groupsKey);
        $this->assertNotSame($descriptorsKey, $groupsKey);
    }

    public function testClearLabelDescriptorGroupsCacheDeletesTheCachedEntry(): void
    {
        $this->sdkProvider->method('getCache')->willReturn(new InMemoryCache());

        $this->labelDescriptorGroupService->expects($this->exactly(2))
            ->method('all')
            ->willReturn([$this->makeSdkGroup(4, ['en-US' => 'Card'], 10)]);

        $this->gateway->getLabelDescriptorGroups();
        $this->gateway->clearLabelDescriptorGroupsCache();
        $this->gateway->getLabelDescriptorGroups();
    }

    public function testClearLabelDescriptorsCacheDeletesTheCachedEntry(): void
    {
        $this->sdkProvider->method('getCache')->willReturn(new InMemoryCache());

        $this->labelDescriptorService->expects($this->exactly(2))
            ->method('all')
            ->willReturn([$this->makeSdkDescriptor(1001, ['en-US' => 'Card Brand'], 4, 10, 'HUMAN', 2)]);

        $this->gateway->getLabelDescriptors();
        $this->gateway->clearLabelDescriptorsCache();
        $this->gateway->getLabelDescriptors();
    }

    #[DataProvider('operationProvider')]
    public function testEveryOperationCallsTheSdkWithNoParameters(string $sdkService, string $method): void
    {
        $this->{$sdkService}->expects($this->once())
            ->method('all')
            ->with()
            ->willReturn([]);

        $this->gateway->{$method}();
    }

    #[DataProvider('operationProvider')]
    public function testEveryOperationFailsWhenTheSdkDoesNotReturnAList(string $sdkService, string $method): void
    {
        $this->{$sdkService}->method('all')->willReturn(null);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('unexpected response'));

        $this->expectException(GlobalDataException::class);
        $this->gateway->{$method}();
    }

    #[DataProvider('operationProvider')]
    public function testEveryOperationLogsStructuredContextAndWrapsSdkFailures(
        string $sdkService,
        string $method,
    ): void {
        $sdkException = new \Exception('SDK unavailable');
        $this->{$sdkService}->method('all')->willThrowException($sdkException);

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
    public function testEveryOperationReturnsAnEmptyCollectionWhenTheApiReportsNothing(
        string $sdkService,
        string $method,
    ): void {
        $this->{$sdkService}->method('all')->willReturn([]);

        $this->assertTrue($this->gateway->{$method}()->isEmpty());
    }

    #[DataProvider('operationProvider')]
    public function testEveryWrappedFailureKeepsTheSdkExceptionAsPrevious(string $sdkService, string $method): void
    {
        $sdkException = new \Exception('SDK unavailable');
        $this->{$sdkService}->method('all')->willThrowException($sdkException);

        try {
            $this->gateway->{$method}();
            $this->fail('Expected a GlobalDataException.');
        } catch (GlobalDataException $e) {
            $this->assertSame($sdkException, $e->getPrevious());
        }
    }

    // ---------------------------------------------------------------------
    // Mapping
    // ---------------------------------------------------------------------

    public function testGetCurrenciesMapsEveryCurrency(): void
    {
        $this->currencyService->method('all')->willReturn([
            $this->makeSdkCurrency('CHF', 2, 'Swiss Franc', 756),
            $this->makeSdkCurrency('JPY', 0, 'Japanese Yen', 392),
        ]);

        $collection = $this->gateway->getCurrencies();

        $this->assertCount(2, $collection);
        $chf = $collection->findByCurrencyCode('CHF');
        $this->assertNotNull($chf);
        $this->assertSame(2, $chf->fractionDigits);
        $this->assertSame('Swiss Franc', $chf->name);
        $this->assertSame(756, $chf->numericCode);
    }

    public function testGetLabelDescriptorGroupsIsServedFromTheCacheOnASecondCall(): void
    {
        $this->sdkProvider->method('getCache')->willReturn(new InMemoryCache());

        $this->labelDescriptorGroupService->expects($this->once())
            ->method('all')
            ->willReturn([$this->makeSdkGroup(4, ['en-US' => 'Card'], 10)]);

        $this->gateway->getLabelDescriptorGroups();
        $this->gateway->getLabelDescriptorGroups();
    }

    public function testGetLabelDescriptorGroupsMapsEveryGroup(): void
    {
        $this->labelDescriptorGroupService->method('all')->willReturn([
            $this->makeSdkGroup(4, ['en-US' => 'Card'], 10),
        ]);

        $group = $this->gateway->getLabelDescriptorGroups()->findById(4);

        $this->assertNotNull($group);
        $this->assertSame('Card', $group->name->localize('en-US'));
        $this->assertSame(10, $group->weight);
    }

    // ---------------------------------------------------------------------
    // Caching: opt-in, cache-aside for label descriptors and their groups
    // ---------------------------------------------------------------------

    public function testGetLabelDescriptorsFallsThroughToTheApiOnEveryCallWhenNoCacheIsConfigured(): void
    {
        // $this->sdkProvider is a plain mock; getCache() is left unstubbed and
        // therefore returns null, exactly like a client that never configured one.
        $this->labelDescriptorService->expects($this->exactly(2))
            ->method('all')
            ->willReturn([$this->makeSdkDescriptor(1001, ['en-US' => 'Card Brand'], 4, 10, 'HUMAN', 2)]);

        $this->gateway->getLabelDescriptors();
        $this->gateway->getLabelDescriptors();
    }

    public function testGetLabelDescriptorsForceRefreshBypassesAndRepopulatesTheCache(): void
    {
        $this->sdkProvider->method('getCache')->willReturn(new InMemoryCache());

        $this->labelDescriptorService->expects($this->exactly(2))
            ->method('all')
            ->willReturnOnConsecutiveCalls(
                [$this->makeSdkDescriptor(1001, ['en-US' => 'Card Brand'], 4, 10, 'HUMAN', 2)],
                [$this->makeSdkDescriptor(1002, ['en-US' => 'Acquirer Reference'], 4, 10, 'HUMAN', 2)],
            );

        $this->gateway->getLabelDescriptors();
        $this->gateway->setForceRefresh(true);
        $refreshed = $this->gateway->getLabelDescriptors();
        $this->gateway->setForceRefresh(false);
        // A plain call afterwards must see the refreshed value, not the original
        // one: forceRefresh repopulates the cache rather than only bypassing it.
        $third = $this->gateway->getLabelDescriptors();

        $this->assertNotNull($refreshed->findById(1002));
        $this->assertNotNull($third->findById(1002));
        $this->assertNull($third->findById(1001));
    }

    public function testGetLabelDescriptorsIsServedFromTheCacheOnASecondCall(): void
    {
        $this->sdkProvider->method('getCache')->willReturn(new InMemoryCache());

        $this->labelDescriptorService->expects($this->once())
            ->method('all')
            ->willReturn([$this->makeSdkDescriptor(1001, ['en-US' => 'Card Brand'], 4, 10, 'HUMAN', 2)]);

        $first = $this->gateway->getLabelDescriptors();
        $second = $this->gateway->getLabelDescriptors();

        $this->assertNotNull($first->findById(1001));
        $this->assertNotNull($second->findById(1001));
    }

    public function testGetLabelDescriptorsMapsEveryDescriptor(): void
    {
        $this->labelDescriptorService->method('all')->willReturn([
            $this->makeSdkDescriptor(1001, ['en-US' => 'Card Brand'], 4, 10, 'HUMAN', 2),
        ]);

        $descriptor = $this->gateway->getLabelDescriptors()->findById(1001);

        $this->assertNotNull($descriptor);
        $this->assertSame('Card Brand', $descriptor->name->localize('en-US'));
        // Normalized to an ID on every API version, whatever the payload carries.
        $this->assertSame(4, $descriptor->groupId);
        $this->assertSame(10, $descriptor->weight);
        $this->assertSame('HUMAN', $descriptor->category);
        $this->assertSame(2, $descriptor->type);
    }

    public function testGetLanguagesMapsEveryLanguageAndResolvesThePrimaryVariant(): void
    {
        $this->languageService->method('all')->willReturn([
            $this->makeSdkLanguage('en', 'en-GB', 'eng', 'English', 'GB', 'n != 1', false),
            $this->makeSdkLanguage('en', 'en-US', 'eng', 'English', 'US', 'n != 1', true),
        ]);

        $collection = $this->gateway->getLanguages();

        $this->assertCount(2, $collection);
        $primary = $collection->findPrimary('en');
        $this->assertNotNull($primary);
        $this->assertSame('en-US', $primary->ietfCode);
        $this->assertSame('US', $primary->countryCode);
        $this->assertSame('n != 1', $primary->pluralExpression);
    }

    public function testGetPaymentConnectorsMapsEveryConnector(): void
    {
        $this->paymentConnectorService->method('all')->willReturn([$this->makeSdkConnector()]);

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

    public function testLabelDescriptorsAndGroupsCacheUnderDifferentKeysAndDoNotCollide(): void
    {
        $cache = new InMemoryCache();
        $this->sdkProvider->method('getCache')->willReturn($cache);

        $this->labelDescriptorService->method('all')
            ->willReturn([$this->makeSdkDescriptor(1001, ['en-US' => 'Card Brand'], 4, 10, 'HUMAN', 2)]);
        $this->labelDescriptorGroupService->method('all')
            ->willReturn([$this->makeSdkGroup(4, ['en-US' => 'Card'], 10)]);

        $this->gateway->getLabelDescriptors();
        $this->gateway->getLabelDescriptorGroups();

        $this->assertCount(2, $cache->all());
    }

    public function testSetCacheTtlOverridesTheDefaultTtlUsedWhenCaching(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects($this->once())
            ->method('set')
            ->with(self::anything(), self::anything(), 60);
        $this->sdkProvider->method('getCache')->willReturn($cache);

        $this->labelDescriptorService->method('all')
            ->willReturn([$this->makeSdkDescriptor(1001, ['en-US' => 'Card Brand'], 4, 10, 'HUMAN', 2)]);

        $this->gateway->setCacheTtl(60);
        $this->gateway->getLabelDescriptors();
    }

    public function testSuccessfulReadIsLoggedAtDebugAndNeverAtInfo(): void
    {
        // These are routine, high-frequency reads of static reference data. A
        // confirmation that one worked carries no business meaning, so it must not
        // compete at info level with records that describe actual state changes.
        $this->currencyService->method('all')
            ->willReturn([$this->makeSdkCurrency('CHF', 2, 'Swiss Franc', 756)]);

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

/**
 * Minimal in-memory PSR-16 cache for exercising {@see \WeArePlanet\PluginCore\SharedKernel\CacheAwareTrait}
 * against real get/set/delete behavior, rather than mocking each call individually.
 */
class InMemoryCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $items = [];

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->items[$key] = $value;

        return true;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string)$key, $value, $ttl);
        }

        return true;
    }
}
