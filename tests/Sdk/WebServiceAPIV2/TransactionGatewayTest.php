<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TransactionGateway;
use WeArePlanet\PluginCore\Settings\IntegrationMode as IntegrationModeEnum;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\Sdk\Model\PaymentMethodConfiguration as SdkPaymentMethodConfiguration;
use WeArePlanet\Sdk\Model\FailureReason as SdkFailureReason;
use WeArePlanet\Sdk\Service\PaymentMethodConfigurationsService as SdkPaymentMethodConfigurationService;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;
use WeArePlanet\Sdk\Model\TransactionState as SdkTransactionState;
use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\Transaction\TransactionContext;
use WeArePlanet\Sdk\Model\LineItemCreate as SdkLineItemCreate;
use WeArePlanet\Sdk\Model\LineItemType as SdkLineItemType;
use WeArePlanet\Sdk\Model\TransactionCreate as SdkTransactionCreate;
use WeArePlanet\Sdk\Model\CreationEntityState as SdkCreationEntityState;
use WeArePlanet\Sdk\Model\PaymentMethodConfigurationListResponse;

class TransactionGatewayTest extends TestCase
{
    private TransactionGateway $gateway;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkTransactionsService $sdkTransactionsService;
    private MockObject|SdkPaymentMethodConfigurationService $sdkPaymentConfigService;
    private MockObject|LoggerInterface $logger;
    private MockObject|Settings $settings;

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
            ->with($spaceId, null, null, null, null, $query)
            ->willReturn([$sdkItem1]);

        $results = $this->gateway->getPaymentMethodConfigurations($spaceId);

        $this->assertCount(1, $results);
        $this->assertEquals(10, $results[0]->id);
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

        $results = $this->gateway->getAvailablePaymentMethods($spaceId, $transactionId);

        $this->assertCount(1, $results);
        $this->assertEquals(55, $results[0]->id);
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
        $context->billingAddress->familyName = 'Tester';
        $context->billingAddress->givenName = 'Tim';
        $context->billingAddress->organizationName = 'Test Org';
        $context->billingAddress->phoneNumber = '+41791234567';
        $context->billingAddress->postcode = '8400';
        $context->billingAddress->street = 'Test Street 1';
        $context->billingAddress->salutation = 'Mr';
        $context->billingAddress->dateOfBirth = new \DateTimeImmutable('1990-01-01');
        $context->billingAddress->salesTaxNumber = 'CHE-123.456.789';

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
}
