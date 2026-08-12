<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Transaction\RecurringTransactionGatewayInterface;
use WeArePlanet\PluginCore\Transaction\RecurringTransactionService;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\TransactionContext;
use WeArePlanet\PluginCore\Transaction\TransactionService;
use WeArePlanet\PluginCore\Token\Exception\MissingTokenException;
use WeArePlanet\PluginCore\Token\State as TokenState;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Address\Address;

class RecurringTransactionServiceTest extends TestCase
{
    private RecurringTransactionService $service;
    private MockObject|TransactionService $transactionService;
    private MockObject|RecurringTransactionGatewayInterface $gateway;
    private MockObject|LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->transactionService = $this->createMock(TransactionService::class);
        $this->gateway = $this->createMock(RecurringTransactionGatewayInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new RecurringTransactionService(
            $this->transactionService,
            $this->gateway,
            $this->logger,
        );
    }

    public function testProcessRecurringPayment(): void
    {
        $spaceId = 123;
        $transactionId = 456;
        $newTransactionId = 789;

        $originalTransaction = new Transaction();
        $originalTransaction->id = $transactionId;
        $originalTransaction->spaceId = $spaceId;
        $originalTransaction->merchantReference = 'ORD-001';
        $originalTransaction->customerId = 'CUST-001';
        $originalTransaction->currency = 'USD';

        $token = new Token(id: 555, state: TokenState::ACTIVE);
        $originalTransaction->token = $token;

        $address = new Address();
        $address->city = 'City';
        $originalTransaction->billingAddress = $address;

        $newTransaction = new Transaction();
        $newTransaction->id = $newTransactionId;
        $newTransaction->spaceId = $spaceId;

        $this->transactionService->expects($this->once())
            ->method('getTransaction')
            ->with($spaceId, $transactionId)
            ->willReturn($originalTransaction);

        $this->transactionService->expects($this->once())
            ->method('createTransaction')
            ->with($this->callback(function (TransactionContext $context) use ($spaceId, $token, $address) {
                return $context->spaceId === $spaceId
                    && $context->merchantReference === 'ORD-001_R'
                    && $context->customerId === 'CUST-001'
                    && $context->currencyCode === 'USD'
                    && $context->token === $token
                    && $context->billingAddress === $address;
            }))
            ->willReturn($newTransaction);

        $this->gateway->expects($this->once())
            ->method('processRecurringPayment')
            ->with($spaceId, $newTransactionId)
            ->willReturn($newTransaction);

        $result = $this->service->processRecurringPayment($spaceId, $transactionId);

        $this->assertSame($newTransaction, $result);
    }

    /**
     * Verifies that a clear MissingTokenException is thrown when the original
     * transaction has no token. Recurring payments require the original
     * transaction to have been created with tokenizationMode = FORCE_CREATION.
     */
    public function testProcessRecurringPaymentThrowsWhenTokenMissing(): void
    {
        $spaceId = 123;
        $transactionId = 456;

        $originalTransaction = new Transaction();
        $originalTransaction->id = $transactionId;
        $originalTransaction->spaceId = $spaceId;
        $originalTransaction->merchantReference = 'ORD-001';
        $originalTransaction->customerId = 'CUST-001';
        $originalTransaction->currency = 'USD';

        // No token — simulates a transaction created without tokenizationMode
        $originalTransaction->token = null;

        $address = new Address();
        $address->city = 'City';
        $originalTransaction->billingAddress = $address;

        $this->transactionService->expects($this->once())
            ->method('getTransaction')
            ->with($spaceId, $transactionId)
            ->willReturn($originalTransaction);

        // No transaction creation, no gateway call
        $this->transactionService->expects($this->never())
            ->method('createTransaction');

        $this->gateway->expects($this->never())
            ->method('processRecurringPayment');

        $this->expectException(MissingTokenException::class);
        $this->expectExceptionMessage('tokenizationMode = FORCE_CREATION');

        $this->service->processRecurringPayment($spaceId, $transactionId);
    }
}
