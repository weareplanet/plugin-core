<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Token\Exception\MissingTokenException;
use WeArePlanet\PluginCore\Token\State as TokenState;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;
use WeArePlanet\PluginCore\Transaction\RecurringTransactionGatewayInterface;
use WeArePlanet\PluginCore\Transaction\RecurringTransactionService;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\TransactionContext;
use WeArePlanet\PluginCore\Transaction\TransactionService;

class RecurringTransactionServiceTest extends TestCase
{
    private MockObject|RecurringTransactionGatewayInterface $gateway;
    private MockObject|LoggerInterface $logger;
    private RecurringTransactionService $service;
    private MockObject|TransactionService $transactionService;

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

    /**
     * Verify that a successful recurring payment copies the context, creates a new transaction,
     * and processes it via the recurring payment gateway.
     */
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
     * Verify that processing a recurring payment throws an exception when the
     * transaction has a token but is missing a billing address, which is required
     * for taxing and billing purposes.
     */
    public function testProcessRecurringPaymentThrowsWhenBillingAddressMissing(): void
    {
        $spaceId = 123;
        $transactionId = 456;

        $originalTransaction = new Transaction();
        $originalTransaction->id = $transactionId;
        $originalTransaction->spaceId = $spaceId;

        $token = new Token(id: 555, state: TokenState::ACTIVE);
        $originalTransaction->token = $token;
        $originalTransaction->billingAddress = null;

        $this->transactionService->expects($this->once())
            ->method('getTransaction')
            ->with($spaceId, $transactionId)
            ->willReturn($originalTransaction);

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage("Transaction $transactionId has no billing address.");

        $this->service->processRecurringPayment($spaceId, $transactionId);
    }

    /**
     * Verify that processing a recurring payment throws an exception when the
     * transaction does not have an associated token.
     */
    public function testProcessRecurringPaymentThrowsWhenTokenMissing(): void
    {
        $spaceId = 123;
        $transactionId = 456;

        $originalTransaction = new Transaction();
        $originalTransaction->id = $transactionId;
        $originalTransaction->spaceId = $spaceId;
        $originalTransaction->token = null;

        $this->transactionService->expects($this->once())
            ->method('getTransaction')
            ->with($spaceId, $transactionId)
            ->willReturn($originalTransaction);

        $this->expectException(MissingTokenException::class);
        $this->expectExceptionMessage(
            "Transaction $transactionId has no token. "
            . "The original transaction must be created with tokenizationMode = FORCE_CREATION "
            . "to enable recurring payments.",
        );

        $this->service->processRecurringPayment($spaceId, $transactionId);
    }
}
