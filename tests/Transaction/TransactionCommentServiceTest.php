<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Transaction\TransactionCommentCollection;
use WeArePlanet\PluginCore\Transaction\TransactionCommentGatewayInterface;
use WeArePlanet\PluginCore\Transaction\TransactionCommentService;

class TransactionCommentServiceTest extends TestCase
{
    private const SPACE_ID = 42;
    private const TRANSACTION_ID = 1234;

    private MockObject|TransactionCommentGatewayInterface $gateway;
    private TransactionCommentService $service;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(TransactionCommentGatewayInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new TransactionCommentService($this->gateway, $logger);
    }

    public function testGetCommentsDelegatesToGatewayAndReturnsResult(): void
    {
        $comments = new TransactionCommentCollection();

        $this->gateway->expects($this->once())
            ->method('getComments')
            ->with(self::SPACE_ID, self::TRANSACTION_ID)
            ->willReturn($comments);

        $this->assertSame(
            $comments,
            $this->service->getComments(self::SPACE_ID, self::TRANSACTION_ID),
        );
    }

    public function testGetCommentsReturnsAnEmptyCollectionWhenThereAreNone(): void
    {
        $this->gateway->expects($this->once())
            ->method('getComments')
            ->willReturn(new TransactionCommentCollection());

        $this->assertCount(0, $this->service->getComments(self::SPACE_ID, self::TRANSACTION_ID));
    }
}
