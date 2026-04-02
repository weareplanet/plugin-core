<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodSorting;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Transaction\State;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\TransactionContext;
use WeArePlanet\PluginCore\Transaction\TransactionGatewayInterface;
use WeArePlanet\PluginCore\Transaction\TransactionPersistenceInterface;
use WeArePlanet\PluginCore\Transaction\TransactionSearchCriteria;
use WeArePlanet\PluginCore\Transaction\TransactionService;

class TransactionServiceTest extends TestCase
{
    private MockObject|LineItemConsistencyService $consistencyService;
    private MockObject|TransactionGatewayInterface $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|TransactionPersistenceInterface $persistence;
    private TransactionService $service;
    private MockObject|Settings $settings;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(TransactionGatewayInterface::class);
        $this->consistencyService = $this->createMock(LineItemConsistencyService::class);
        $this->settings = $this->createMock(Settings::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->persistence = $this->createMock(TransactionPersistenceInterface::class);

        $this->service = new TransactionService(
            $this->gateway,
            $this->consistencyService,
            $this->logger,
        );
    }

    public function testCreateNewTransactionPersistsId(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->merchantReference = 'ORDER-1';
        $context->currencyCode = 'CHF';
        $context->expectedGrandTotal = 100.00;

        $this->consistencyService->method('ensureConsistency')
            ->willReturnArgument(0);

        $transaction = new Transaction();
        $transaction->id = 100;
        $transaction->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('create')
            ->with($context)
            ->willReturn($transaction);

        $this->persistence->expects($this->once())
            ->method('persist')
            ->with(100);

        $this->service->upsert($context, $this->persistence);
    }

    public function testCreateTransactionAllowsZeroTotal(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->merchantReference = 'ZERO-TOTAL';
        $context->currencyCode = 'CHF';
        $context->expectedGrandTotal = 0.00;
        $context->lineItems = [];

        $this->consistencyService->method('ensureConsistency')
            ->willReturnArgument(0);

        // Expect delegating to gateway instead of throwing exception
        $expectedTx = new Transaction();
        $expectedTx->id = 100;
        $expectedTx->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('create')
            ->with($context)
            ->willReturn($expectedTx);

        $result = $this->service->createTransaction($context);
        $this->assertEquals(100, $result->id);
    }



    public function testCreateWithNegativeTotalAutoFix(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->merchantReference = 'AUTO-FIX';
        $context->currencyCode = 'CHF';
        $context->expectedGrandTotal = -50.00; // Total 100 - 150 = -50

        $item1 = new LineItem();
        $item1->amountIncludingTax = 100.00;
        $item1->type = LineItem::TYPE_PRODUCT;

        $item2 = new LineItem();
        $item2->amountIncludingTax = -150.00;
        $item2->type = LineItem::TYPE_DISCOUNT;

        $context->lineItems = [$item1, $item2];

        // 1. Mock sanitization: 100, -150 -> 100, -100
        $sanitizedItem1 = clone $item1;
        $sanitizedItem2 = clone $item2;
        $sanitizedItem2->amountIncludingTax = -100.00;

        $this->consistencyService->expects($this->once())
            ->method('sanitizeNegativeLineItems')
            ->with($context->lineItems)
            ->willReturn([$sanitizedItem1, $sanitizedItem2]);

        // 2. Mock consistency check: 0.00 total
        $this->consistencyService->expects($this->once())
            ->method('ensureConsistency')
            ->with($this->anything(), 0.00, 'CHF')
            ->willReturnArgument(0);

        $transaction = new Transaction();
        $transaction->id = 777;
        $transaction->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('create')
            ->willReturn($transaction);

        $result = $this->service->createTransaction($context);

        $this->assertEquals(777, $result->id);
        $this->assertEquals(0.00, $context->expectedGrandTotal);
        $this->assertEquals(-100.00, $context->lineItems[1]->amountIncludingTax);
    }

    public function testGetAvailablePaymentMethodsSortsByName(): void
    {
        $spaceId = 123;
        $transactionId = 456;

        $methodA = new PaymentMethod(
            id: 1,
            spaceId: $spaceId,
            state: 'active',
            name: 'Zeus Payment',
            title: ['en-US' => 'Zeus Payment'],
            description: 'Desc',
            descriptionMap: ['en-US' => 'Desc'],
            sortOrder: 1,
            imageUrl: null,
        );

        $methodB = new PaymentMethod(
            id: 2,
            spaceId: $spaceId,
            state: 'active',
            name: 'Apollo Payment',
            title: ['en-US' => 'Apollo Payment'],
            description: 'Desc',
            descriptionMap: ['en-US' => 'Desc'],
            sortOrder: 1,
            imageUrl: null,
        );

        // Gateway returns unsorted (Z then A)
        $this->gateway->method('getAvailablePaymentMethods')
            ->willReturn([$methodA, $methodB]);

        // 1. Default (No Sort)
        $default = $this->service->getAvailablePaymentMethods($spaceId, $transactionId, PaymentMethodSorting::DEFAULT);
        $this->assertSame($methodA, $default[0]); // Z
        $this->assertSame($methodB, $default[1]); // A

        // 2. Sorted by Name
        $sorted = $this->service->getAvailablePaymentMethods($spaceId, $transactionId, PaymentMethodSorting::NAME);
        $this->assertSame($methodB, $sorted[0]); // A
        $this->assertSame($methodA, $sorted[1]); // Z
    }

    public function testGetLatestTransactionsDelegatesToGatewayWithDefaults(): void
    {
        $spaceId = 123;
        $limit = 5;
        $tx = new Transaction();
        $tx->state = State::PENDING;
        $expectedResults = [$tx];

        $this->gateway->expects($this->once())
            ->method('search')
            ->with($this->callback(function (int $argSpaceId) use ($spaceId) {
                return $argSpaceId === $spaceId;
            }), $this->callback(function (TransactionSearchCriteria $argCriteria) use ($limit) {
                return $argCriteria->limit === $limit
                    && $argCriteria->sortField === 'id'
                    && $argCriteria->sortOrder === 'DESC';
            }))
            ->willReturn($expectedResults);

        $this->assertSame($expectedResults, $this->service->getLatestTransactions($spaceId, $limit));
    }

    public function testGetPaymentUrlDelegatesToGateway(): void
    {
        $spaceId = 1;
        $txId = 999;
        $url = "http://example.com";

        $this->gateway->expects($this->once())
            ->method('getPaymentUrl')
            ->with($spaceId, $txId)
            ->willReturn($url);

        $this->assertSame($url, $this->service->getPaymentUrl($spaceId, $txId));
    }

    public function testGetTransactionDelegatesToGateway(): void
    {
        $spaceId = 1;
        $txId = 999;
        $expectedTx = new Transaction();
        $expectedTx->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('get')
            ->with($spaceId, $txId)
            ->willReturn($expectedTx);

        $this->assertSame($expectedTx, $this->service->getTransaction($spaceId, $txId));
    }
    public function testSearchTransactionsDelegatesToGateway(): void
    {
        $spaceId = 123;
        $criteria = new TransactionSearchCriteria();
        $tx = new Transaction();
        $tx->state = State::PENDING;
        $expectedResults = [$tx];

        $this->gateway->expects($this->once())
            ->method('search')
            ->with($spaceId, $criteria)
            ->willReturn($expectedResults);

        $this->assertSame($expectedResults, $this->service->searchTransactions($spaceId, $criteria));
    }

    public function testUpdateExistingTransactionDoesNotPersist(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->transactionId = 123;

        // 1. Mock Find (Return Domain Object)
        $domainTx = new Transaction();
        $domainTx->id = 123;
        $domainTx->version = 5;
        $domainTx->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('find')
            ->willReturn($domainTx);

        // 2. Mock Update (Pass ID 123, Version 5)
        $updateResult = new Transaction();
        $updateResult->id = 123;
        $updateResult->version = 6;
        $updateResult->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('update')
            ->with(123, 5, $context)
            ->willReturn($updateResult);

        $this->persistence->expects($this->never())->method('persist');

        $result = $this->service->upsert($context, $this->persistence);

        $this->assertEquals(123, $result->id);
    }

    public function testUpdateFailureFallsBackToCreate(): void
    {
        // 1. Setup a FULLY valid Context
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->transactionId = 123;
        $context->merchantReference = 'TEST-FALLBACK';
        $context->expectedGrandTotal = 100.00;
        $context->currencyCode = 'CHF';
        $context->language = 'en-US';
        $context->customerId = 'TEST-CUST-1';
        $context->lineItems = [];
        $context->successUrl = 'https://example.com/success';
        $context->failedUrl = 'https://example.com/fail';

        $context->billingAddress = new Address();
        $context->billingAddress->givenName = 'Test';
        $context->billingAddress->familyName = 'User';

        // 2. Mock Find
        $domainTx = new Transaction();
        $domainTx->id = 123;
        $domainTx->version = 1;
        $domainTx->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('find')
            ->with(1, 123)
            ->willReturn($domainTx);

        // 3. Mock Update (FAILURE)
        $this->gateway->expects($this->once())
            ->method('update')
            ->with(123, 1, $context)
            ->willThrowException(new \Exception("Update failed"));

        // 4. Mock Create (Fallback)
        $fallbackResult = new Transaction();
        $fallbackResult->id = 999;
        $fallbackResult->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('create')
            ->with($context)
            ->willReturn($fallbackResult);

        // 5. Expect Persistence (New ID)
        $this->persistence->expects($this->once())
            ->method('persist')
            ->with(999);

        // Execute
        $result = $this->service->upsert($context, $this->persistence);

        // Assert
        $this->assertEquals(999, $result->id);
    }

    public function testUpsertWithNegativeTotalAutoFix(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->transactionId = 123;
        $context->expectedGrandTotal = -20.00;

        $item1 = new LineItem();
        $item1->amountIncludingTax = 80.00;
        $item1->type = LineItem::TYPE_PRODUCT;

        $item2 = new LineItem();
        $item2->amountIncludingTax = -100.00;
        $item2->type = LineItem::TYPE_DISCOUNT;

        $context->lineItems = [$item1, $item2];

        $existing = new Transaction();
        $existing->id = 123;
        $existing->version = 1;
        $existing->state = State::PENDING;

        $this->gateway->method('find')->willReturn($existing);

        // Expect sanitization
        $this->consistencyService->expects($this->once())
            ->method('sanitizeNegativeLineItems')
            ->willReturn($context->lineItems); // Just return same for simplicity in mock

        $this->gateway->expects($this->once())
            ->method('update')
            ->willReturn($existing);

        $persistenceMock = $this->createMock(TransactionPersistenceInterface::class);

        $this->service->upsert($context, $persistenceMock);

        $this->assertEquals(0.00, $context->expectedGrandTotal);
    }
}
