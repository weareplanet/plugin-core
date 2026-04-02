<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\SdkV1;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\SdkV1\TransactionCompletionGateway;
use WeArePlanet\PluginCore\Transaction\Completion\State;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletion;
use WeArePlanet\Sdk\Model\TransactionCompletion as SdkTransactionCompletion;
use WeArePlanet\Sdk\Model\TransactionCompletionState;
use WeArePlanet\Sdk\Model\TransactionVoid as SdkTransactionVoid;
use WeArePlanet\Sdk\Model\TransactionVoidState;
use WeArePlanet\Sdk\Service\TransactionCompletionService as SdkTransactionCompletionService;
use WeArePlanet\Sdk\Service\TransactionVoidService as SdkTransactionVoidService;

class TransactionCompletionGatewayTest extends TestCase
{
    private MockObject|SdkTransactionCompletionService $completionService;
    private TransactionCompletionGateway $gateway;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkTransactionVoidService $voidService;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->completionService = $this->createMock(SdkTransactionCompletionService::class);
        $this->voidService = $this->createMock(SdkTransactionVoidService::class);

        $this->sdkProvider->method('getService')
            ->willReturnMap([
                [SdkTransactionCompletionService::class, $this->completionService],
                [SdkTransactionVoidService::class, $this->voidService],
            ]);

        $this->gateway = new TransactionCompletionGateway($this->sdkProvider);
    }

    public function testCaptureReturnsCompletion(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $sdkCompletion = new SdkTransactionCompletion();
        $sdkCompletion->setId(10);
        $sdkCompletion->setLinkedTransaction($transactionId);
        $sdkCompletion->setState(TransactionCompletionState::SUCCESSFUL);

        $this->completionService->expects($this->once())
            ->method('completeOnline')
            ->with($spaceId, $transactionId)
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
        $sdkVoid->setState(TransactionVoidState::SUCCESSFUL);

        $this->voidService->expects($this->once())
            ->method('voidOnline')
            ->with($spaceId, $transactionId)
            ->willReturn($sdkVoid);

        $result = $this->gateway->void($spaceId, $transactionId);

        $this->assertEquals('SUCCESSFUL', $result);
    }
}
