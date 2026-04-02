<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\TokenService;
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
    private MockObject|TokenService $tokenService;
    private MockObject|TransactionService $transactionService;

    protected function setUp(): void
    {
        $this->transactionService = $this->createMock(TransactionService::class);
        $this->gateway = $this->createMock(RecurringTransactionGatewayInterface::class);
        $this->tokenService = $this->createMock(TokenService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new RecurringTransactionService(
            $this->transactionService,
            $this->gateway,
            $this->tokenService,
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

        $token = new Token();
        $token->id = 555;
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

        $this->tokenService->expects($this->never())
            ->method('createTokenForTransaction');

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

    public function testProcessRecurringPaymentCreatesTokenIfMissing(): void
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

        // No token initially
        $originalTransaction->token = null;

        $address = new Address();
        $address->city = 'City';
        $originalTransaction->billingAddress = $address;

        $newToken = new Token();
        $newToken->id = 777;

        $newTransaction = new Transaction();
        $newTransaction->id = $newTransactionId;
        $newTransaction->spaceId = $spaceId;

        $this->transactionService->expects($this->once())
            ->method('getTransaction')
            ->with($spaceId, $transactionId)
            ->willReturn($originalTransaction);

        $this->tokenService->expects($this->once())
            ->method('createTokenForTransaction')
            ->with($spaceId, $transactionId)
            ->willReturn($newToken);

        $this->transactionService->expects($this->once())
            ->method('createTransaction')
            ->with($this->callback(function (TransactionContext $context) use ($spaceId, $newToken) {
                return $context->spaceId === $spaceId
                    && $context->token === $newToken;
            }))
            ->willReturn($newTransaction);

        $this->gateway->expects($this->once())
            ->method('processRecurringPayment')
            ->with($spaceId, $newTransactionId)
            ->willReturn($newTransaction);

        $result = $this->service->processRecurringPayment($spaceId, $transactionId);

        $this->assertSame($newTransaction, $result);
    }
}
