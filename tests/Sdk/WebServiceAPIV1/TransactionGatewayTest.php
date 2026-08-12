<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV1;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\Customer\PersonalDetails;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\TransactionGateway;
use WeArePlanet\PluginCore\Settings\IntegrationMode;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\SharedKernel\Url;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;
use WeArePlanet\PluginCore\Transaction\TransactionContext;
use WeArePlanet\PluginCore\Transaction\TransactionEnvironment;
use WeArePlanet\PluginCore\Transaction\TransactionPaymentMethod;
use WeArePlanet\Sdk\ApiException;
use WeArePlanet\Sdk\Http\ConnectionException;
use WeArePlanet\Sdk\Model\FailureReason as SdkFailureReason;
use WeArePlanet\Sdk\Model\LineItem as SdkLineItemResponse;
use WeArePlanet\Sdk\Model\LineItemType as SdkLineItemType;
use WeArePlanet\Sdk\Model\PaymentConnectorConfiguration as SdkPaymentConnectorConfiguration;
use WeArePlanet\Sdk\Model\PaymentMethodConfiguration as SdkConfiguration;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;
use WeArePlanet\Sdk\Model\TransactionCreate as SdkTransactionCreate;
use WeArePlanet\Sdk\Model\TransactionState as SdkTransactionState;
use WeArePlanet\Sdk\Service\PaymentMethodConfigurationService as SdkPaymentMethodConfigurationService;
use WeArePlanet\Sdk\Service\TransactionIframeService as SdkTransactionIframeService;
use WeArePlanet\Sdk\Service\TransactionLightboxService as SdkTransactionLightboxService;
use WeArePlanet\Sdk\Service\TransactionPaymentPageService as SdkTransactionPaymentPageService;
use WeArePlanet\Sdk\Service\TransactionService as SdkTransactionService;
use WeArePlanet\Sdk\VersioningException;

class TransactionGatewayTest extends TestCase
{
    private TransactionGateway $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkPaymentMethodConfigurationService $sdkPaymentConfigService;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkTransactionService $sdkTransactionService;
    private MockObject|Settings $settings;

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
     * @return array<string, array{0: IntegrationMode, 1: class-string, 2: string}>
     */
    public static function integrationModeProvider(): array
    {
        return [
            'Payment Page' => [
                IntegrationMode::PAYMENT_PAGE,
                SdkTransactionPaymentPageService::class,
                'paymentPageUrl',
            ],
            'Iframe' => [
                IntegrationMode::IFRAME,
                SdkTransactionIframeService::class,
                'javascriptUrl',
            ],
            'Lightbox' => [
                IntegrationMode::LIGHTBOX,
                SdkTransactionLightboxService::class,
                'javascriptUrl',
            ],
        ];
    }

    protected function setUp(): void
    {
        $this->sdkTransactionService = $this->createMock(SdkTransactionService::class);
        $this->sdkPaymentConfigService = $this->createMock(SdkPaymentMethodConfigurationService::class);

        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->sdkProvider->method('getService')
            ->willReturnMap([
                [SdkTransactionService::class, $this->sdkTransactionService],
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

        $this->sdkTransactionService->expects($this->once())
            ->method('create')
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
     * Verifies that line item types are correctly mapped to SDK types.
     */
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
        $context->personalDetails = new PersonalDetails(emailAddress: 'test@example.com');

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

        // Mock SDK creation to capture the object
        $this->sdkTransactionService->expects($this->once())
            ->method('create')
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

        $this->sdkTransactionService->expects($this->once())
            ->method('create')
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

    public function testFetchAvailablePaymentMethodsUsesSettingsMode(): void
    {
        $spaceId = 123;
        $transactionId = 999;

        // Simulate Setting = IFRAME
        $this->settings->method('getIntegrationMode')
            ->willReturn(IntegrationMode::IFRAME);

        // Mock SDK Response
        $sdkItem = new SdkConfiguration();
        $sdkItem->setId(55);
        $sdkItem->setLinkedSpaceId($spaceId);
        $sdkItem->setState(\WeArePlanet\Sdk\Model\CreationEntityState::ACTIVE);
        $sdkItem->setResolvedTitle(['en-US' => 'Invoice']);

        // Expect Gateway to pass 'iframe' string to SDK
        $this->sdkTransactionService->expects($this->once())
            ->method('fetchPaymentMethods')
            ->with($spaceId, $transactionId, 'iframe')
            ->willReturn([$sdkItem]);

        // Run
        $results = $this->gateway->getAvailablePaymentMethods($spaceId, $transactionId, );

        $this->assertCount(1, $results, );
        $this->assertEquals(55, $results->first()->id, );
    }

    public function testFetchPaymentMethodConfigurationsMapsCorrectly(): void
    {
        $spaceId = 123;

        $sdkItem1 = new SdkConfiguration();
        $sdkItem1->setId(10);
        $sdkItem1->setLinkedSpaceId($spaceId);
        $sdkItem1->setState(\WeArePlanet\Sdk\Model\CreationEntityState::ACTIVE);
        $sdkItem1->setResolvedTitle(['en-US' => 'Credit Card']);
        $sdkItem1->setResolvedDescription(['en-US' => 'Pay later']);
        $sdkItem1->setSortOrder(1);
        $sdkItem1->setResolvedImageUrl('http://img.com/card.png');

        $this->sdkPaymentConfigService->expects($this->once())
            ->method('search')
            ->willReturn([$sdkItem1]);

        $results = $this->gateway->getPaymentMethodConfigurations($spaceId, );

        $this->assertCount(1, $results, );
        $this->assertEquals(10, $results->first()->id, );
    }

    #[DataProvider('integrationModeProvider')]
    public function testFetchPaymentUrlDelegatesToCorrectService(
        IntegrationMode $mode,
        string $serviceClass,
        string $methodName,
    ): void {
        $spaceId = 1;
        $txId = 2;
        $expectedUrl = 'https://weareplanet.com/pay';

        // Configure Settings
        $this->settings->method('getIntegrationMode')->willReturn($mode);

        // Mock the specific service
        /** @var class-string $serviceClass */
        $specificServiceMock = $this->createMock($serviceClass);
        $specificServiceMock->expects($this->once())
            ->method($methodName)
            ->with($spaceId, $txId)
            ->willReturn($expectedUrl);

        // RE-CREATE Provider Mock for this specific test
        $cleanSdkProvider = $this->createMock(SdkProvider::class);
        $cleanSdkProvider->method('getService')
            ->willReturnMap([
                [SdkTransactionService::class, $this->sdkTransactionService],
                [SdkPaymentMethodConfigurationService::class, $this->sdkPaymentConfigService],
                [$serviceClass, $specificServiceMock],
            ]);

        // RE-CREATE Gateway with clean provider
        $cleanGateway = new TransactionGateway(
            $cleanSdkProvider,
            $this->logger,
            $this->settings,
        );

        // Run Test
        $url = $cleanGateway->getPaymentUrl($spaceId, $txId);

        $this->assertEquals($expectedUrl, $url);
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

        $this->sdkTransactionService->expects($this->once())
            ->method('read')
            ->with($spaceId, $transactionId)
            ->willReturn($sdkTransaction);

        $transaction = $this->gateway->find($spaceId, $transactionId);

        $this->assertNull($transaction->paymentMethod);

        $this->assertInstanceOf(TransactionEnvironment::class, $transaction->environment);
        $this->assertNull($transaction->environment->spaceViewId);
        $this->assertNull($transaction->environment->language);
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

        $this->sdkTransactionService->expects($this->once())
            ->method('read')
            ->with($spaceId, $transactionId)
            ->willReturn($sdkTransaction);

        $transaction = $this->gateway->find($spaceId, $transactionId);

        $this->assertEquals('Insufficient funds', $transaction->failureReason->localize('en-US'));
        $this->assertEquals('Payment failed, please try again.', $transaction->userFailureMessage->localize('en-US'));

        $this->assertEquals($now->getTimestamp(), $transaction->createdOn->getTimestamp());
        $this->assertEquals($now->getTimestamp(), $transaction->failedOn->getTimestamp());
    }

    /**
     * Verifies that the environment and payment method snapshots are populated
     * from the SDK payload when it carries the full context.
     */
    public function testFindMapsEnvironmentAndPaymentMethodSnapshots(): void
    {
        $spaceId = 123;
        $transactionId = 456;

        $sdkPaymentMethodConfiguration = new SdkConfiguration();
        $sdkPaymentMethodConfiguration->setId(88);
        $sdkPaymentMethodConfiguration->setResolvedImageUrl('https://gateway.test/s/1/resource/payment/visa.svg');

        $sdkConnectorConfiguration = new SdkPaymentConnectorConfiguration();
        $sdkConnectorConfiguration->setPaymentMethodConfiguration($sdkPaymentMethodConfiguration);
        // This API models the connector as a bare ID.
        $sdkConnectorConfiguration->setConnector(31);

        $sdkTransaction = new SdkTransaction();
        $sdkTransaction->setId($transactionId);
        $sdkTransaction->setVersion(1);
        $sdkTransaction->setState(SdkTransactionState::AUTHORIZED);
        $sdkTransaction->setLinkedSpaceId($spaceId);
        $sdkTransaction->setSpaceViewId(7);
        $sdkTransaction->setLanguage('de-CH');
        $sdkTransaction->setPaymentConnectorConfiguration($sdkConnectorConfiguration);

        $this->sdkTransactionService->expects($this->once())
            ->method('read')
            ->with($spaceId, $transactionId)
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

        $this->sdkTransactionService->expects($this->once())
            ->method('read')
            ->with($spaceId, $transactionId)
            ->willReturn($sdkTransaction);

        $transaction = $this->gateway->find($spaceId, $transactionId);

        $this->assertSame(10.00, $transaction->lineItems[0]->discountIncludingTax);
    }

    /**
     * Verifies that a payment connector configuration without an embedded payment
     * method configuration still yields a snapshot, with the unavailable fields null.
     */
    public function testFindMapsPartialPaymentConnectorConfiguration(): void
    {
        $spaceId = 123;
        $transactionId = 456;

        $sdkConnectorConfiguration = new SdkPaymentConnectorConfiguration();
        $sdkConnectorConfiguration->setConnector(31);

        $sdkTransaction = new SdkTransaction();
        $sdkTransaction->setId($transactionId);
        $sdkTransaction->setVersion(1);
        $sdkTransaction->setState(SdkTransactionState::AUTHORIZED);
        $sdkTransaction->setLinkedSpaceId($spaceId);
        $sdkTransaction->setLanguage('en-US');
        $sdkTransaction->setPaymentConnectorConfiguration($sdkConnectorConfiguration);

        $this->sdkTransactionService->expects($this->once())
            ->method('read')
            ->with($spaceId, $transactionId)
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

    public function testFindReturnsNullOn404ApiException(): void
    {
        $spaceId = 123;
        $transactionId = 456;

        $this->sdkTransactionService->expects($this->once())
            ->method('read')
            ->with($spaceId, $transactionId)
            ->willThrowException(new ApiException('Not Found', 404));

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

    public function testFindWrapsApiExceptionOn500(): void
    {
        $spaceId = 123;
        $transactionId = 456;

        $this->sdkTransactionService->expects($this->once())
            ->method('read')
            ->with($spaceId, $transactionId)
            ->willThrowException(new ApiException('Internal Server Error', 500));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Gateway: Failed to find transaction'));

        $this->expectException(TransactionException::class);
        $this->gateway->find($spaceId, $transactionId);
    }

    public function testUpdateDoesNotMarkGenericFailureAsRetryable(): void
    {
        $this->sdkTransactionService->method('update')
            ->willThrowException(new \RuntimeException('Something else went wrong.'));

        try {
            $this->gateway->update(456, 1, $this->buildMinimalContext());
            $this->fail('Expected a TransactionException to be thrown.');
        } catch (TransactionException $e) {
            $this->assertFalse($e->isRetryable());
        }
    }

    public function testUpdateMarksConnectionExceptionAsRetryable(): void
    {
        $this->sdkTransactionService->method('update')
            ->willThrowException(new ConnectionException());

        try {
            $this->gateway->update(456, 1, $this->buildMinimalContext());
            $this->fail('Expected a TransactionException to be thrown.');
        } catch (TransactionException $e) {
            $this->assertTrue($e->isRetryable());
        }
    }

    public function testUpdateMarksVersionConflictAsRetryable(): void
    {
        $this->sdkTransactionService->method('update')
            ->willThrowException(new VersioningException('/transaction/update'));

        try {
            $this->gateway->update(456, 1, $this->buildMinimalContext());
            $this->fail('Expected a TransactionException to be thrown.');
        } catch (TransactionException $e) {
            $this->assertTrue($e->isRetryable());
        }
    }
}
