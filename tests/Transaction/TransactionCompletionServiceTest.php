<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;
use WeArePlanet\PluginCore\Transaction\Completion\State;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletion;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletionGatewayInterface;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletionService;

class TransactionCompletionServiceTest extends TestCase
{
    private TransactionCompletionService $service;
    private MockObject|TransactionCompletionGatewayInterface $gateway;
    private MockObject|LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(TransactionCompletionGatewayInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new TransactionCompletionService($this->gateway, $this->logger);
    }

    public function testCaptureSuccess(): void
    {
        $spaceId = 1;
        $transactionId = 123;
        $completion = new TransactionCompletion();
        $completion->id = 456;
        $completion->state = State::SUCCESSFUL;

        $this->gateway->expects($this->once())
            ->method('capture')
            ->with($spaceId, $transactionId)
            ->willReturn($completion);

        $result = $this->service->capture($spaceId, $transactionId);
        $this->assertSame($completion, $result);
    }

    public function testCaptureFailure(): void
    {
        $this->gateway->expects($this->once())
            ->method('capture')
            ->willThrowException(new \Exception("SDK Error"));

        $this->expectException(TransactionException::class);
        $this->service->capture(1, 123);
    }

    public function testVoidSuccess(): void
    {
        $spaceId = 1;
        $transactionId = 123;
        $state = 'SUCCESSFUL';

        $this->gateway->expects($this->once())
            ->method('void')
            ->with($spaceId, $transactionId)
            ->willReturn($state);

        $result = $this->service->void($spaceId, $transactionId);
        $this->assertSame($state, $result);
    }

    public function testVoidFailure(): void
    {
        $this->gateway->expects($this->once())
            ->method('void')
            ->willThrowException(new \Exception("SDK Error"));

        $this->expectException(TransactionException::class);
        $this->service->void(1, 123);
    }
}
