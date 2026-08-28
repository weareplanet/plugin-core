<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\Customer\CompanyDetails;
use WeArePlanet\PluginCore\Customer\PersonalDetails;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TransactionGateway;
use WeArePlanet\PluginCore\Settings\IntegrationMode as IntegrationModeEnum;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\SharedKernel\Url;
use WeArePlanet\PluginCore\Transaction\CustomersPresence;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;
use WeArePlanet\PluginCore\Transaction\TransactionContext;
use WeArePlanet\PluginCore\Transaction\TransactionEnvironment;
use WeArePlanet\PluginCore\Transaction\TransactionPaymentMethod;
use WeArePlanet\PluginCore\Transaction\TransactionSearchCriteria;
use WeArePlanet\Sdk\ApiException;
use WeArePlanet\Sdk\Model\CreationEntityState as SdkCreationEntityState;
use WeArePlanet\Sdk\Model\CustomersPresence as SdkCustomersPresence;
use WeArePlanet\Sdk\Model\FailureReason as SdkFailureReason;
use WeArePlanet\Sdk\Model\LineItem as SdkLineItemResponse;
use WeArePlanet\Sdk\Model\LineItemCreate as SdkLineItemCreate;
use WeArePlanet\Sdk\Model\LineItemType as SdkLineItemType;
use WeArePlanet\Sdk\Model\PaymentConnector as SdkPaymentConnector;
use WeArePlanet\Sdk\Model\PaymentConnectorConfiguration as SdkPaymentConnectorConfiguration;
use WeArePlanet\Sdk\Model\PaymentMethodConfiguration as SdkPaymentMethodConfiguration;
use WeArePlanet\Sdk\Model\PaymentMethodConfigurationListResponse;
use WeArePlanet\Sdk\Model\PaymentMethodConfigurationSearchResponse as SdkPaymentMethodConfigurationSearchResponse;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;
use WeArePlanet\Sdk\Model\TransactionCreate as SdkTransactionCreate;
use WeArePlanet\Sdk\Model\TransactionState as SdkTransactionState;
use WeArePlanet\Sdk\Service\PaymentMethodConfigurationsService as SdkPaymentMethodConfigurationService;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;

class TransactionGatewayTest extends TestCase
{
    private TransactionGateway $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkPaymentMethodConfigurationService $sdkPaymentConfigService;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkTransactionsService $sdkTransactionsService;
    private MockObject|Settings $settings;

    /**
     * @return array<string, array{0: IntegrationModeEnum, 1: string}>
     */
    public static function integrationModeProvider(): array
    {
        return [
            'Payment Page' => [
                IntegrationModeEnum::PAYMENT_PAGE,
                'getPaymentTransactionsIdPaymentPageUrl',
            ],
            'Iframe' => [
                IntegrationModeEnum::IFRAME,
                'getPaymentTransactionsIdIframeJavascriptUrl',
            ],
            'Lightbox' => [
                IntegrationModeEnum::LIGHTBOX,
                'getPaymentTransactionsIdLightboxJavascriptUrl',
            ],
        ];
    }

    protected function setUp(): void
    {
        $this->sdkTransactionsService = $this->createMock(SdkTransactionsService::class);
        $this->sdkPaymentConfigService = $this->createMock(SdkPaymentMethodConfigurationService::class);

        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->sdkProvider->method('getService')
            ->willReturnMap([
                [SdkTransactionsService::class, $this->sdkTransactionsService],
                [SdkPaymentMethodConfigurationService::class, $this->sdkPaymentConfigService],
            ]);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->settings = $this->createMock(Settings::class);

        $this->gateway = new TransactionGateway(
            $this->sdkProvider,
            $this->logger,
            $this->settings,
        );
    }

    public function testCreateTransactionMapsLineItemType(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 123;
        $context->merchantReference = 'MAPPING-TEST';
        $context->currencyCode = 'CHF';
        $context->language = 'en-US';
        $context->successUrl = new Url('http://success');
        $context->failedUrl = new Url('http://failed');
        $context->customerId = 'CUST-1';
        $context->billingAddress = new Address();
        $context->billingAddress->city = 'Winterthur';
        $context->billingAddress->country = 'CH';
        $context->billingAddress->phoneNumber = '+41791234567';
        $context->billingAddress->postcode = '8400';
        $context->billingAddress->street = 'Test Street 1';
        $context->personalDetails = new PersonalDetails(
            dateOfBirth: new \DateTimeImmutable('1990-01-01'),
            emailAddress: 'test@example.com',
            familyName: 'Tester',
            givenName: 'Tim',
            salutation: 'Mr',
        );
        $context->companyDetails = new CompanyDetails(
            organizationName: 'Test Org',
            salesTaxNumber: 'CHE-123.456.789',
        );

        $item = new LineItem();
        $item->uniqueId = 'SI-1';
        $item->sku = 'SKU-1';
        $item->name = 'Shipping Item';
        $item->quantity = 1.0;
        $item->amountIncludingTax = 10.00;
        $item->type = LineItem::TYPE_SHIPPING;

        $context->lineItems = new LineItemCollection($item);

        $sdkTx = new SdkTransaction();
        $sdkTx->setId(777);
        $sdkTx->setLinkedSpaceId(123);
        $sdkTx->setVersion(1);
        $sdkTx->setState(SdkTransactionState::PENDING);

        // V2: postPaymentTransactions($space, $create)
        $this->sdkTransactionsService->expects($this->once())
            ->method('postPaymentTransactions')
            ->with(
                $this->equalTo(123),
                $this->callback(function (SdkTransactionCreate $create) {
                    $items = $create->getLineItems();
                    return count($items) === 1 && $items[0]->getType() === SdkLineItemType::SHIPPING;
                }),
            )
            ->willReturn($sdkTx);

        $this->gateway->create($context);
    }

    /**
     * Verifies that a line item's discountIncludingTax is mapped onto the
     * SDK line item when set.
     */
    public function testCreateTransactionMapsDiscountIncludingTaxWhenSet(): void
    {
        $context = $this->buildMinimalContext();
        $context->personalDetails = new PersonalDetails(emailAddress: 'test@example.com');

        $item = new LineItem();
        $item->uniqueId = 'SKU-1';
        $item->sku = 'SKU-1';
        $item->name = 'Discounted Product';
        $item->quantity = 1.0;
        $item->amountIncludingTax = 90.00;
        $item->discountIncludingTax = 10.00;

        $context->lineItems = new LineItemCollection($item);

        $sdkTx = new SdkTransaction();
        $sdkTx->setId(778);
        $sdkTx->setLinkedSpaceId(123);
        $sdkTx->setVersion(1);
        $sdkTx->setState(SdkTransactionState::PENDING);

        $this->sdkTransactionsService->expects($this->once())
            ->method('postPaymentTransactions')
            ->with(
                $this->equalTo(123),
                $this->callback(function (SdkTransactionCreate $create) {
                    $items = $create->getLineItems();
                    return count($items) === 1 && $items[0]->getDiscountIncludingTax() === 10.00;
                }),
            )
            ->willReturn($sdkTx);

        $this->gateway->create($context);
    }

    /**
     * Verifies that discountIncludingTax is omitted from the SDK line item
     * when not set, rather than being sent as an explicit null.
     */
    public function testCreateTransactionOmitsDiscountIncludingTaxWhenNull(): void
    {
        $context = $this->buildMinimalContext();
        $context->personalDetails = new PersonalDetails(emailAddress: 'test@example.com');

        $item = new LineItem();
        $item->uniqueId = 'SKU-1';
        $item->sku = 'SKU-1';
        $item->name = 'Regular Product';
        $item->quantity = 1.0;
        $item->amountIncludingTax = 100.00;

        $context->lineItems = new LineItemCollection($item);

        $sdkTx = new SdkTransaction();
        $sdkTx->setId(779);
        $sdkTx->setLinkedSpaceId(123);
        $sdkTx->setVersion(1);
        $sdkTx->setState(SdkTransactionState::PENDING);

        $this->sdkTransactionsService->expects($this->once())
            ->method('postPaymentTransactions')
            ->with(
                $this->equalTo(123),
                $this->callback(function (SdkTransactionCreate $create) {
                    $items = $create->getLineItems();
                    return count($items) === 1 && $items[0]->getDiscountIncludingTax() === null;
                }),
            )
            ->willReturn($sdkTx);

        $this->gateway->create($context);
    }

    /**
     * Verifies that customersPresence and deviceSessionIdentifier are
     * forwarded to the SDK transaction when the context provides them.
     */
    public function testCreateTransactionMapsCustomersPresenceAndDeviceSessionIdentifierWhenSet(): void
    {
        $context = $this->buildMinimalContext();
        $context->personalDetails = new PersonalDetails(emailAddress: 'test@example.com');
        $context->customersPresence = CustomersPresence::VIRTUAL_PRESENT;
        $context->deviceSessionIdentifier = 'device-session-123';

        $sdkTx = new SdkTransaction();
        $sdkTx->setId(780);
        $sdkTx->setLinkedSpaceId(123);
        $sdkTx->setVersion(1);
        $sdkTx->setState(SdkTransactionState::PENDING);

        $this->sdkTransactionsService->expects($this->once())
            ->method('postPaymentTransactions')
            ->with(
                $this->equalTo(123),
                $this->callback(function (SdkTransactionCreate $create) {
                    return $create->getCustomersPresence() === SdkCustomersPresence::VIRTUAL_PRESENT
                        && $create->getDeviceSessionIdentifier() === 'device-session-123';
                }),
            )
            ->willReturn($sdkTx);

        $this->gateway->create($context);
    }

    /**
     * Verifies that customersPresence and deviceSessionIdentifier are omitted
     * from the SDK transaction when the context leaves them unset, rather than
     * being sent as explicit nulls.
     */
    public function testCreateTransactionOmitsCustomersPresenceAndDeviceSessionIdentifierWhenNull(): void
    {
        $context = $this->buildMinimalContext();
        $context->personalDetails = new PersonalDetails(emailAddress: 'test@example.com');

        $sdkTx = new SdkTransaction();
        $sdkTx->setId(781);
        $sdkTx->setLinkedSpaceId(123);
        $sdkTx->setVersion(1);
        $sdkTx->setState(SdkTransactionState::PENDING);

        $this->sdkTransactionsService->expects($this->once())
            ->method('postPaymentTransactions')
            ->with(
                $this->equalTo(123),
                $this->callback(function (SdkTransactionCreate $create) {
                    return $create->getCustomersPresence() === null
                        && $create->getDeviceSessionIdentifier() === null;
                }),
            )
            ->willReturn($sdkTx);

        $this->gateway->create($context);
    }

    /**
     * A context without personal details must still create a transaction:
     * customerEmailAddress is optional on the API, and the SDK setter rejects
     * an explicit null, so the field has to be omitted entirely.
     */
    public function testCreateTransactionOmitsCustomerEmailAddressWhenPersonalDetailsMissing(): void
    {
        $context = $this->buildMinimalContext();
        $context->personalDetails = null;

        $sdkTx = new SdkTransaction();
        $sdkTx->setId(782);
        $sdkTx->setLinkedSpaceId(123);
        $sdkTx->setVersion(1);
        $sdkTx->setState(SdkTransactionState::PENDING);

        $this->sdkTransactionsService->expects($this->once())
            ->method('postPaymentTransactions')
            ->with(
                $this->equalTo(123),
                $this->callback(
                    fn (SdkTransactionCreate $create) => $create->getCustomerEmailAddress() === null,
                ),
            )
            ->willReturn($sdkTx);

        $this->gateway->create($context);
    }

    /**
     * Personal details present but without an email address must behave the
     * same as no personal details at all.
     */
    public function testCreateTransactionOmitsCustomerEmailAddressWhenEmailMissing(): void
    {
        $context = $this->buildMinimalContext();
        $context->personalDetails = new PersonalDetails(givenName: 'Tim');

        $sdkTx = new SdkTransaction();
        $sdkTx->setId(783);
        $sdkTx->setLinkedSpaceId(123);
        $sdkTx->setVersion(1);
        $sdkTx->setState(SdkTransactionState::PENDING);

        $this->sdkTransactionsService->expects($this->once())
            ->method('postPaymentTransactions')
            ->with(
                $this->equalTo(123),
                $this->callback(
                    fn (SdkTransactionCreate $create) => $create->getCustomerEmailAddress() === null,
                ),
            )
            ->willReturn($sdkTx);

        $this->gateway->create($context);
    }

    /**
     * invoiceMerchantReference must reach the SDK transaction when the
     * context provides it: it was previously settable on TransactionContext
     * but never forwarded to the API.
     */
    public function testCreateTransactionMapsInvoiceMerchantReferenceWhenSet(): void
    {
        $context = $this->buildMinimalContext();
        $context->invoiceMerchantReference = 'INV-001';

        $sdkTx = new SdkTransaction();
        $sdkTx->setId(789);
        $sdkTx->setLinkedSpaceId(123);
        $sdkTx->setVersion(1);
        $sdkTx->setState(SdkTransactionState::PENDING);

        $this->sdkTransactionsService->expects($this->once())
            ->method('postPaymentTransactions')
            ->with(
                $this->equalTo(123),
                $this->callback(fn (SdkTransactionCreate $create) => $create->getInvoiceMerchantReference() === 'INV-001'),
            )
            ->willReturn($sdkTx);

        $this->gateway->create($context);
    }

    /**
     * Without an invoiceMerchantReference, the field must be omitted from
     * the SDK payload rather than sent as an explicit null.
     */
    public function testCreateTransactionOmitsInvoiceMerchantReferenceWhenNull(): void
    {
        $context = $this->buildMinimalContext();

        $sdkTx = new SdkTransaction();
        $sdkTx->setId(790);
        $sdkTx->setLinkedSpaceId(123);
        $sdkTx->setVersion(1);
        $sdkTx->setState(SdkTransactionState::PENDING);

        $this->sdkTransactionsService->expects($this->once())
            ->method('postPaymentTransactions')
            ->with(
                $this->equalTo(123),
                $this->callback(fn (SdkTransactionCreate $create) => $create->getInvoiceMerchantReference() === null),
            )
            ->willReturn($sdkTx);

        $this->gateway->create($context);
    }

    /**
     * metaData and allowedPaymentMethodConfigurations must reach the SDK
     * transaction when the context provides them: both were previously
     * settable on TransactionContext but never forwarded to the API.
     */
    public function testCreateTransactionMapsMetaDataAndAllowedPaymentMethodConfigurationsWhenSet(): void
    {
        $context = $this->buildMinimalContext();
        $context->metaData = ['orderSource' => 'web'];
        $context->allowedPaymentMethodConfigurations = [111, 222];

        $sdkTx = new SdkTransaction();
        $sdkTx->setId(784);
        $sdkTx->setLinkedSpaceId(123);
        $sdkTx->setVersion(1);
        $sdkTx->setState(SdkTransactionState::PENDING);

        $this->sdkTransactionsService->expects($this->once())
            ->method('postPaymentTransactions')
            ->with(
                $this->equalTo(123),
                $this->callback(function (SdkTransactionCreate $create) {
                    return $create->getMetaData() === ['orderSource' => 'web']
                        && $create->getAllowedPaymentMethodConfigurations() === [111, 222];
                }),
            )
            ->willReturn($sdkTx);

        $this->gateway->create($context);
    }

    /**
     * With no metaData or allowedPaymentMethodConfigurations set, both fields
     * must be omitted from the SDK payload rather than sent as empty arrays.
     */
    public function testCreateTransactionOmitsMetaDataAndAllowedPaymentMethodConfigurationsWhenEmpty(): void
    {
        $context = $this->buildMinimalContext();

        $sdkTx = new SdkTransaction();
        $sdkTx->setId(785);
        $sdkTx->setLinkedSpaceId(123);
        $sdkTx->setVersion(1);
        $sdkTx->setState(SdkTransactionState::PENDING);

        $this->sdkTransactionsService->expects($this->once())
            ->method('postPaymentTransactions')
            ->with(
                $this->equalTo(123),
                $this->callback(function (SdkTransactionCreate $create) {
                    return $create->getMetaData() === null
                        && $create->getAllowedPaymentMethodConfigurations() === null;
                }),
            )
            ->willReturn($sdkTx);

        $this->gateway->create($context);
    }

    /**
     * Wraps configurations in the paged response shape the SDK actually returns.
     *
     * @param list<SdkPaymentMethodConfiguration> $configs
     */
    private function configurationSearchResponse(array $configs, bool $hasMore = false): SdkPaymentMethodConfigurationSearchResponse
    {
        $response = new SdkPaymentMethodConfigurationSearchResponse();
        $response->setData($configs);
        $response->setHasMore($hasMore);

        return $response;
    }

    public function testFetchAvailablePaymentMethodsUsesSettingsMode(): void
    {
        $spaceId = 123;
        $transactionId = 999;

        // Mode 'iframe'
        $this->settings->method('getIntegrationMode')
            ->willReturn(IntegrationModeEnum::IFRAME);

        $sdkItem = new SdkPaymentMethodConfiguration();
        $sdkItem->setId(55);
        $sdkItem->setLinkedSpaceId($spaceId);
        $sdkItem->setResolvedTitle(['en-US' => 'Invoice']);
        $sdkItem->setState(SdkCreationEntityState::ACTIVE);

        // V2: getPaymentTransactionsIdPaymentMethodConfigurations
        $response = new PaymentMethodConfigurationListResponse();
        $response->setData([$sdkItem]);

        $this->sdkTransactionsService->expects($this->once())
            ->method('getPaymentTransactionsIdPaymentMethodConfigurations')
            ->with($transactionId, 'iframe', $spaceId)
            ->willReturn($response);

        $results = $this->gateway->getAvailablePaymentMethods($spaceId, $transactionId, );

        $this->assertCount(1, $results, );
        $this->assertEquals(55, $results->first()->id, );
    }

    /**
     * A space can hold more active configurations than one page returns, so the
     * gateway must page until the API reports no more: a truncated list would
     * silently hide payment methods that are still active.
     */
    public function testFetchPaymentMethodConfigurationsPagesUntilTheApiReportsNoMore(): void
    {
        $spaceId = 123;
        $query = "state:ACTIVE";

        $makeConfig = static function (int $id) use ($spaceId): SdkPaymentMethodConfiguration {
            $config = new SdkPaymentMethodConfiguration();
            $config->setId($id);
            $config->setLinkedSpaceId($spaceId);
            $config->setState(SdkCreationEntityState::ACTIVE);
            $config->setResolvedTitle(['en-US' => "Method $id"]);
            $config->setSortOrder(1);

            return $config;
        };

        $firstPage = $this->configurationSearchResponse([$makeConfig(11)], true);
        $secondPage = $this->configurationSearchResponse([$makeConfig(12)], false);

        $this->sdkPaymentConfigService->expects($this->exactly(2))
            ->method('getPaymentMethodConfigurationsSearch')
            ->willReturnCallback(
                function (int $space, $expand, int $limit, int $offset, $order, string $actualQuery) use ($spaceId, $query, $firstPage, $secondPage) {
                    self::assertSame($spaceId, $space);
                    self::assertSame(100, $limit);
                    self::assertSame($query, $actualQuery);

                    // The second page must resume after the record already read.
                    return $offset === 0 ? $firstPage : $secondPage;
                },
            );

        $results = $this->gateway->getPaymentMethodConfigurations($spaceId);

        $this->assertCount(2, $results);
    }

    public function testFetchPaymentMethodConfigurationsMapsCorrectly(): void
    {
        $spaceId = 123;
        $query = "state:ACTIVE";

        $sdkItem1 = new SdkPaymentMethodConfiguration();
        $sdkItem1->setId(10);
        $sdkItem1->setLinkedSpaceId($spaceId);
        $sdkItem1->setResolvedTitle(['en-US' => 'Credit Card']);
        $sdkItem1->setResolvedDescription(['en-US' => 'Pay later']);
        $sdkItem1->setSortOrder(1);
        $sdkItem1->setResolvedImageUrl('http://img.com/card.png');
        $sdkItem1->setState(SdkCreationEntityState::ACTIVE);

        // V2 Search with query string: getPaymentMethodConfigurationsSearch($space, $expand, $limit, $offset, $order, $query)
        $this->sdkPaymentConfigService->expects($this->once())
            ->method('getPaymentMethodConfigurationsSearch')
            ->with($spaceId, null, 100, 0, null, $query)
            ->willReturn($this->configurationSearchResponse([$sdkItem1]));

        $results = $this->gateway->getPaymentMethodConfigurations($spaceId, );

        $this->assertCount(1, $results, );
        $this->assertEquals(10, $results->first()->id, );
    }

    #[DataProvider('integrationModeProvider')]
    public function testFetchPaymentUrlDelegatesToCorrectMethod(
        IntegrationModeEnum $mode,
        string $methodName,
    ): void {
        $spaceId = 1;
        $txId = 2;
        $expectedUrl = 'https://weareplanet.com/pay';

        $this->settings->method('getIntegrationMode')->willReturn($mode);

        // Expect the method call on 'transactionsService' directly
        $this->sdkTransactionsService->expects($this->once())
            ->method($methodName)
            ->with($txId, $spaceId)
            ->willReturn($expectedUrl);

        $url = $this->gateway->getPaymentUrl($spaceId, $txId);

        $this->assertEquals($expectedUrl, $url);
    }

    public function testFindMapsDiagnosticsAndTimeline(): void
    {
        $spaceId = 123;
        $transactionId = 456;
        $now = new \DateTime();

        $failureReason = new SdkFailureReason();
        $failureReason->setDescription(['en-US' => 'Insufficient funds']);
        $failureReason->setName(['en-US' => 'No Money']);

        $sdkTransaction = new SdkTransaction();
        $sdkTransaction->setId($transactionId);
        $sdkTransaction->setVersion(1);
        $sdkTransaction->setState(SdkTransactionState::FAILED);
        $sdkTransaction->setLinkedSpaceId($spaceId);
        $sdkTransaction->setLanguage('en-US');
        $sdkTransaction->setUserFailureMessage('Payment failed, please try again.');
        $sdkTransaction->setFailureReason($failureReason);

        $sdkTransaction->setCreatedOn($now);
        $sdkTransaction->setAuthorizedOn($now);
        $sdkTransaction->setProcessingOn($now);
        $sdkTransaction->setFailedOn($now);
        $sdkTransaction->setCompletedOn($now);

        // V2: getPaymentTransactionsId
        $this->sdkTransactionsService->expects($this->once())
            ->method('getPaymentTransactionsId')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkTransaction);

        $transaction = $this->gateway->find($spaceId, $transactionId);

        $this->assertEquals('Insufficient funds', $transaction->failureReason->localize('en-US'));
        $this->assertEquals('Payment failed, please try again.', $transaction->userFailureMessage->localize('en-US'));
        $this->assertEquals($now->getTimestamp(), $transaction->createdOn->getTimestamp());
    }

    /**
     * Verifies that the environment and payment method snapshots are populated
     * from the SDK payload when it carries the full context.
     */
    public function testFindMapsEnvironmentAndPaymentMethodSnapshots(): void
    {
        $spaceId = 123;
        $transactionId = 456;

        $sdkPaymentMethodConfiguration = new SdkPaymentMethodConfiguration();
        $sdkPaymentMethodConfiguration->setId(88);
        $sdkPaymentMethodConfiguration->setResolvedImageUrl('https://gateway.test/s/1/resource/payment/visa.svg');

        // Unlike WebServiceAPIV1, this SDK version models the connector as an object.
        $sdkConnector = new SdkPaymentConnector();
        $sdkConnector->setId(31);

        $sdkConnectorConfiguration = new SdkPaymentConnectorConfiguration();
        $sdkConnectorConfiguration->setPaymentMethodConfiguration($sdkPaymentMethodConfiguration);
        $sdkConnectorConfiguration->setConnector($sdkConnector);

        $sdkTransaction = new SdkTransaction();
        $sdkTransaction->setId($transactionId);
        $sdkTransaction->setVersion(1);
        $sdkTransaction->setState(SdkTransactionState::AUTHORIZED);
        $sdkTransaction->setLinkedSpaceId($spaceId);
        $sdkTransaction->setSpaceViewId(7);
        $sdkTransaction->setLanguage('de-CH');
        $sdkTransaction->setPaymentConnectorConfiguration($sdkConnectorConfiguration);

        $this->sdkTransactionsService->expects($this->once())
            ->method('getPaymentTransactionsId')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkTransaction);

        $transaction = $this->gateway->find($spaceId, $transactionId);

        $this->assertInstanceOf(TransactionEnvironment::class, $transaction->environment);
        $this->assertSame(7, $transaction->environment->spaceViewId);
        $this->assertSame('de-CH', $transaction->environment->language);

        $this->assertInstanceOf(TransactionPaymentMethod::class, $transaction->paymentMethod);
        $this->assertSame(88, $transaction->paymentMethod->paymentMethodId);
        $this->assertSame(31, $transaction->paymentMethod->connectorId);
        $this->assertSame(
            'https://gateway.test/s/1/resource/payment/visa.svg',
            $transaction->paymentMethod->resolvedImageUrl,
        );
    }

    /**
     * Verifies that a transaction without a payment connector configuration maps
     * without error, leaving the payment method snapshot null while the
     * environment snapshot is still built from whatever the payload carries.
     */
    public function testFindLeavesPaymentMethodNullWhenConnectorConfigurationIsMissing(): void
    {
        $spaceId = 123;
        $transactionId = 456;

        $sdkTransaction = new SdkTransaction();
        $sdkTransaction->setId($transactionId);
        $sdkTransaction->setVersion(1);
        $sdkTransaction->setState(SdkTransactionState::PENDING);
        $sdkTransaction->setLinkedSpaceId($spaceId);

        $this->sdkTransactionsService->expects($this->once())
            ->method('getPaymentTransactionsId')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkTransaction);

        $transaction = $this->gateway->find($spaceId, $transactionId);

        $this->assertNull($transaction->paymentMethod);

        $this->assertInstanceOf(TransactionEnvironment::class, $transaction->environment);
        $this->assertNull($transaction->environment->spaceViewId);
        $this->assertNull($transaction->environment->language);
    }

    /**
     * Verifies that a payment connector configuration without an embedded payment
     * method configuration still yields a snapshot, with the unavailable fields null.
     */
    public function testFindMapsPartialPaymentConnectorConfiguration(): void
    {
        $spaceId = 123;
        $transactionId = 456;

        $sdkConnector = new SdkPaymentConnector();
        $sdkConnector->setId(31);

        $sdkConnectorConfiguration = new SdkPaymentConnectorConfiguration();
        $sdkConnectorConfiguration->setConnector($sdkConnector);

        $sdkTransaction = new SdkTransaction();
        $sdkTransaction->setId($transactionId);
        $sdkTransaction->setVersion(1);
        $sdkTransaction->setState(SdkTransactionState::AUTHORIZED);
        $sdkTransaction->setLinkedSpaceId($spaceId);
        $sdkTransaction->setLanguage('en-US');
        $sdkTransaction->setPaymentConnectorConfiguration($sdkConnectorConfiguration);

        $this->sdkTransactionsService->expects($this->once())
            ->method('getPaymentTransactionsId')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkTransaction);

        $transaction = $this->gateway->find($spaceId, $transactionId);

        $this->assertInstanceOf(TransactionPaymentMethod::class, $transaction->paymentMethod);
        $this->assertSame(31, $transaction->paymentMethod->connectorId);
        $this->assertNull($transaction->paymentMethod->paymentMethodId);
        $this->assertNull($transaction->paymentMethod->resolvedImageUrl);

        $this->assertInstanceOf(TransactionEnvironment::class, $transaction->environment);
        $this->assertNull($transaction->environment->spaceViewId);
        $this->assertSame('en-US', $transaction->environment->language);
    }

    /**
     * Verifies that a line item's discountIncludingTax is mapped back onto
     * the domain LineItem when reading a transaction.
     */
    public function testFindMapsLineItemDiscountIncludingTax(): void
    {
        $spaceId = 123;
        $transactionId = 456;

        $sdkLineItem = new SdkLineItemResponse();
        $sdkLineItem->setUniqueId('SKU-1');
        $sdkLineItem->setSku('SKU-1');
        $sdkLineItem->setName('Discounted Product');
        $sdkLineItem->setQuantity(1.0);
        $sdkLineItem->setAmountIncludingTax(90.00);
        $sdkLineItem->setUnitPriceIncludingTax(90.00);
        $sdkLineItem->setDiscountIncludingTax(10.00);
        $sdkLineItem->setType(SdkLineItemType::PRODUCT);

        $sdkTransaction = new SdkTransaction();
        $sdkTransaction->setId($transactionId);
        $sdkTransaction->setVersion(1);
        $sdkTransaction->setState(SdkTransactionState::AUTHORIZED);
        $sdkTransaction->setLinkedSpaceId($spaceId);
        $sdkTransaction->setLineItems([$sdkLineItem]);

        $this->sdkTransactionsService->expects($this->once())
            ->method('getPaymentTransactionsId')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkTransaction);

        $transaction = $this->gateway->find($spaceId, $transactionId);

        $this->assertSame(10.00, $transaction->lineItems[0]->discountIncludingTax);
    }

    public function testFindWrapsApiExceptionOn500(): void
    {
        $spaceId = 123;
        $transactionId = 456;

        $this->sdkTransactionsService->expects($this->once())
            ->method('getPaymentTransactionsId')
            ->with($transactionId, $spaceId)
            ->willThrowException(new ApiException('Internal Server Error', 500));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Gateway: Failed to find transaction'));

        $this->expectException(TransactionException::class);
        $this->gateway->find($spaceId, $transactionId);
    }

    public function testFindReturnsNullOn404ApiException(): void
    {
        $spaceId = 123;
        $transactionId = 456;

        $this->sdkTransactionsService->expects($this->once())
            ->method('getPaymentTransactionsId')
            ->with($transactionId, $spaceId)
            ->willThrowException(new ApiException('Not Found', 404));

        // The IDs live in the context now, not in the message text.
        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                $this->stringContains('Transaction not found'),
                $this->callback(function (array $context) use ($spaceId, $transactionId): bool {
                    $this->assertSame($spaceId, $context['spaceId']);
                    $this->assertSame($transactionId, $context['transactionId']);

                    return true;
                }),
            );

        $result = $this->gateway->find($spaceId, $transactionId);
        $this->assertNull($result);
    }

    /**
     * Verifies that the search method correctly constructs the 'order' parameter
     * using a colon separator as required by WeArePlanet API V2.
     *
     * @return void
     */
    public function testSearchUsesColonForOrder(): void
    {
        $spaceId = 123;
        $criteria = new \WeArePlanet\PluginCore\Transaction\TransactionSearchCriteria();
        $criteria->sortField = 'createdOn';
        $criteria->sortOrder = 'DESC';
        $criteria->limit = 10;

        $this->sdkTransactionsService->expects($this->once())
            ->method('getPaymentTransactionsSearch')
            ->with(
                $this->equalTo($spaceId),
                $this->equalTo(null),
                $this->equalTo(10),
                $this->equalTo(null),
                $this->equalTo('createdOn:DESC'), // Assert colon separator
                $this->equalTo(''),
            )
            ->willReturn([]);

        $this->gateway->search($spaceId, $criteria);
    }

    private function buildMinimalContext(): TransactionContext
    {
        $context = new TransactionContext();
        $context->spaceId = 123;
        $context->merchantReference = 'UPDATE-TEST';
        $context->currencyCode = 'CHF';
        $context->language = 'en-US';
        $context->customerId = 'CUST-1';
        $context->billingAddress = new Address();
        $context->billingAddress->city = 'Winterthur';
        $context->billingAddress->country = 'CH';

        return $context;
    }

    /**
     * invoiceMerchantReference must also reach the SDK payload on update,
     * mirroring the create() mapping.
     */
    public function testUpdateMapsInvoiceMerchantReferenceWhenSet(): void
    {
        $context = $this->buildMinimalContext();
        $context->invoiceMerchantReference = 'INV-002';

        $sdkTx = new SdkTransaction();
        $sdkTx->setId(791);
        $sdkTx->setLinkedSpaceId(123);
        $sdkTx->setVersion(2);
        $sdkTx->setState(SdkTransactionState::PENDING);

        $this->sdkTransactionsService->expects($this->once())
            ->method('patchPaymentTransactionsId')
            ->with(
                456,
                $this->equalTo(123),
                $this->callback(fn ($pending) => $pending->getInvoiceMerchantReference() === 'INV-002'),
            )
            ->willReturn($sdkTx);

        $this->gateway->update(456, 1, $context);
    }

    /**
     * metaData and allowedPaymentMethodConfigurations must also reach the SDK
     * payload on update, mirroring the create() mapping.
     */
    public function testUpdateMapsMetaDataAndAllowedPaymentMethodConfigurationsWhenSet(): void
    {
        $context = $this->buildMinimalContext();
        $context->metaData = ['orderSource' => 'web'];
        $context->allowedPaymentMethodConfigurations = [111, 222];

        $sdkTx = new SdkTransaction();
        $sdkTx->setId(786);
        $sdkTx->setLinkedSpaceId(123);
        $sdkTx->setVersion(2);
        $sdkTx->setState(SdkTransactionState::PENDING);

        $this->sdkTransactionsService->expects($this->once())
            ->method('patchPaymentTransactionsId')
            ->with(
                456,
                $this->equalTo(123),
                $this->callback(function ($pending) {
                    return $pending->getMetaData() === ['orderSource' => 'web']
                        && $pending->getAllowedPaymentMethodConfigurations() === [111, 222];
                }),
            )
            ->willReturn($sdkTx);

        $this->gateway->update(456, 1, $context);
    }

    public function testUpdateMarksVersionConflictAsRetryable(): void
    {
        $this->sdkTransactionsService->method('patchPaymentTransactionsId')
            ->willThrowException(new ApiException('Conflict', 409));

        try {
            $this->gateway->update(456, 1, $this->buildMinimalContext());
            $this->fail('Expected a TransactionException to be thrown.');
        } catch (TransactionException $e) {
            $this->assertTrue($e->isRetryable());
        }
    }

    public function testUpdateMarksConnectionExceptionAsRetryable(): void
    {
        $this->sdkTransactionsService->method('patchPaymentTransactionsId')
            ->willThrowException(new ApiException('Connection failed', 0));

        try {
            $this->gateway->update(456, 1, $this->buildMinimalContext());
            $this->fail('Expected a TransactionException to be thrown.');
        } catch (TransactionException $e) {
            $this->assertTrue($e->isRetryable());
        }
    }

    public function testUpdateDoesNotMarkGenericFailureAsRetryable(): void
    {
        $this->sdkTransactionsService->method('patchPaymentTransactionsId')
            ->willThrowException(new \RuntimeException('Something else went wrong.'));

        try {
            $this->gateway->update(456, 1, $this->buildMinimalContext());
            $this->fail('Expected a TransactionException to be thrown.');
        } catch (TransactionException $e) {
            $this->assertFalse($e->isRetryable());
        }
    }
}
