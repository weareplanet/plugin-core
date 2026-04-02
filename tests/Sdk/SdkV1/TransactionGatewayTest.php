<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\SdkV1;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\SdkV1\TransactionGateway;
use WeArePlanet\PluginCore\Settings\IntegrationMode;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Transaction\TransactionContext;
use WeArePlanet\Sdk\Model\FailureReason as SdkFailureReason;
use WeArePlanet\Sdk\Model\LineItemType as SdkLineItemType;
use WeArePlanet\Sdk\Model\PaymentMethodConfiguration as SdkConfiguration;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;
use WeArePlanet\Sdk\Model\TransactionCreate as SdkTransactionCreate;
use WeArePlanet\Sdk\Model\TransactionState as SdkTransactionState;
use WeArePlanet\Sdk\Service\PaymentMethodConfigurationService as SdkPaymentMethodConfigurationService;
use WeArePlanet\Sdk\Service\TransactionIframeService as SdkTransactionIframeService;
use WeArePlanet\Sdk\Service\TransactionLightboxService as SdkTransactionLightboxService;
use WeArePlanet\Sdk\Service\TransactionPaymentPageService as SdkTransactionPaymentPageService;
use WeArePlanet\Sdk\Service\TransactionService as SdkTransactionService;

class TransactionGatewayTest extends TestCase
{
    private TransactionGateway $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkPaymentMethodConfigurationService $sdkPaymentConfigService;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkTransactionService $sdkTransactionService;
    private MockObject|Settings $settings;

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
     * Verifies that line item types are correctly mapped to SDK types.
     */
    public function testCreateTransactionMapsLineItemType(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 123;
        $context->merchantReference = 'MAPPING-TEST';
        $context->currencyCode = 'CHF';
        $context->language = 'en-US';
        $context->successUrl = 'http://success';
        $context->failedUrl = 'http://failed';
        $context->customerId = 'CUST-1';
        $context->billingAddress = new Address();
        $context->billingAddress->emailAddress = 'test@example.com';
        $context->billingAddress->city = 'Winterthur';
        $context->billingAddress->country = 'CH';

        $item = new LineItem();
        $item->uniqueId = 'SI-1';
        $item->sku = 'SKU-1';
        $item->name = 'Shipping Item';
        $item->quantity = 1.0;
        $item->amountIncludingTax = 10.00;
        $item->type = LineItem::TYPE_SHIPPING;

        $context->lineItems = [$item];

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

    public function testFetchAvailablePaymentMethodsUsesSettingsMode(): void
    {
        $spaceId = 123;
        $transactionId = 999;

        // 1. Simulate Setting = IFRAME
        $this->settings->method('getIntegrationMode')
            ->willReturn(IntegrationMode::IFRAME);

        // 2. Mock SDK Response
        $sdkItem = new SdkConfiguration();
        $sdkItem->setId(55);
        $sdkItem->setLinkedSpaceId($spaceId);
        $sdkItem->setResolvedTitle(['en-US' => 'Invoice']);

        // 3. Expect Gateway to pass 'iframe' string to SDK
        $this->sdkTransactionService->expects($this->once())
            ->method('fetchPaymentMethods')
            ->with($spaceId, $transactionId, 'iframe')
            ->willReturn([$sdkItem]);

        // 4. Run
        $results = $this->gateway->getAvailablePaymentMethods($spaceId, $transactionId);

        $this->assertCount(1, $results);
        $this->assertEquals(55, $results[0]->id);
    }

    public function testFetchPaymentMethodConfigurationsMapsCorrectly(): void
    {
        $spaceId = 123;

        $sdkItem1 = new SdkConfiguration();
        $sdkItem1->setId(10);
        $sdkItem1->setLinkedSpaceId($spaceId);
        $sdkItem1->setResolvedTitle(['en-US' => 'Credit Card']);
        $sdkItem1->setResolvedDescription(['en-US' => 'Pay later']);
        $sdkItem1->setSortOrder(1);
        $sdkItem1->setResolvedImageUrl('http://img.com/card.png');

        $this->sdkPaymentConfigService->expects($this->once())
            ->method('search')
            ->willReturn([$sdkItem1]);

        $results = $this->gateway->getPaymentMethodConfigurations($spaceId);

        $this->assertCount(1, $results);
        $this->assertEquals(10, $results[0]->id);
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

        // 1. Configure Settings
        $this->settings->method('getIntegrationMode')->willReturn($mode);

        // 2. Mock the specific service
        /** @var class-string $serviceClass */
        $specificServiceMock = $this->createMock($serviceClass);
        $specificServiceMock->expects($this->once())
            ->method($methodName)
            ->with($spaceId, $txId)
            ->willReturn($expectedUrl);

        // 3. RE-CREATE Provider Mock for this specific test
        $cleanSdkProvider = $this->createMock(SdkProvider::class);
        $cleanSdkProvider->method('getService')
            ->willReturnMap([
                [SdkTransactionService::class, $this->sdkTransactionService],
                [SdkPaymentMethodConfigurationService::class, $this->sdkPaymentConfigService],
                [$serviceClass, $specificServiceMock],
            ]);

        // 4. RE-CREATE Gateway with clean provider
        $cleanGateway = new TransactionGateway(
            $cleanSdkProvider,
            $this->logger,
            $this->settings,
        );

        // 5. Run Test
        $url = $cleanGateway->getPaymentUrl($spaceId, $txId);

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

        $this->sdkTransactionService->expects($this->once())
            ->method('read')
            ->with($spaceId, $transactionId)
            ->willReturn($sdkTransaction);

        $transaction = $this->gateway->find($spaceId, $transactionId);

        $this->assertEquals('Insufficient funds', $transaction->failureReason);
        $this->assertEquals('Payment failed, please try again.', $transaction->userFailureMessage);

        $this->assertEquals($now->getTimestamp(), $transaction->createdOn->getTimestamp());
        $this->assertEquals($now->getTimestamp(), $transaction->failedOn->getTimestamp());
    }
}
