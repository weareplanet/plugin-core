<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TransactionCompletionGateway;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletion;
use WeArePlanet\PluginCore\Transaction\Completion\State;
use WeArePlanet\Sdk\Model\TransactionCompletion as SdkTransactionCompletion;
use WeArePlanet\Sdk\Model\TransactionCompletionState as SdkTransactionCompletionState;
use WeArePlanet\Sdk\Model\TransactionVoid as SdkTransactionVoid;
use WeArePlanet\Sdk\Model\TransactionVoidState as SdkTransactionVoidState;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;

class TransactionCompletionGatewayTest extends TestCase
{
    private TransactionCompletionGateway $gateway;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkTransactionsService $transactionsService;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->transactionsService = $this->createMock(SdkTransactionsService::class);

        $this->sdkProvider->method('getService')
            ->with(SdkTransactionsService::class)
            ->willReturn($this->transactionsService);

        $this->gateway = new TransactionCompletionGateway($this->sdkProvider);
    }

    public function testCaptureReturnsCompletion(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $sdkCompletion = new SdkTransactionCompletion();
        $sdkCompletion->setId(10);
        $sdkCompletion->setLinkedTransaction($transactionId);
        $sdkCompletion->setState(SdkTransactionCompletionState::SUCCESSFUL);

        // V2: postPaymentTransactionsIdCompleteOnline($id, $spaceId)
        $this->transactionsService->expects($this->once())
            ->method('postPaymentTransactionsIdCompleteOnline')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkCompletion);

        $result = $this->gateway->capture($spaceId, $transactionId);

        $this->assertInstanceOf(TransactionCompletion::class, $result);
        $this->assertEquals(10, $result->id);
        $this->assertEquals($transactionId, $result->linkedTransactionId);
        $this->assertEquals(State::SUCCESSFUL, $result->state);
    }

    public function testVoidReturnsStateString(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $sdkVoid = new SdkTransactionVoid();
        $sdkVoid->setState(SdkTransactionVoidState::SUCCESSFUL);

        // V2: postPaymentTransactionsIdVoidOnline($id, $spaceId)
        $this->transactionsService->expects($this->once())
            ->method('postPaymentTransactionsIdVoidOnline')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkVoid);

        $result = $this->gateway->void($spaceId, $transactionId);

        $this->assertEquals('SUCCESSFUL', $result);
    }
}
