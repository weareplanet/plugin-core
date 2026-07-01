<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TransactionCommentGateway;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionCommentException;
use WeArePlanet\Sdk\Model\TransactionComment as SdkTransactionComment;
use WeArePlanet\Sdk\Service\TransactionCommentsService as SdkTransactionCommentsService;

class TransactionCommentGatewayTest extends TestCase
{
    private TransactionCommentGateway $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkTransactionCommentsService $sdkReferenceService;

    protected function setUp(): void
    {
        $this->sdkReferenceService = $this->createMock(SdkTransactionCommentsService::class);

        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->sdkProvider->method('getService')
            ->with(SdkTransactionCommentsService::class)
            ->willReturn($this->sdkReferenceService);

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->gateway = new TransactionCommentGateway(
            $this->sdkProvider,
            $this->logger,
        );
    }

    public function testGetCommentsMapsCorrectly(): void
    {
        $spaceId = 123;
        $transactionId = 456;
        $now = new \DateTime();

        $sdkComment = new SdkTransactionComment();
        $sdkComment->setId(999);
        $sdkComment->setContent('Test Comment');
        $sdkComment->setCreatedOn($now);

        // V2: getPaymentTransactionsTransactionIdComments($transactionId, $spaceId)
        $this->sdkReferenceService->expects($this->once())
            ->method('getPaymentTransactionsTransactionIdComments')
            ->with($transactionId, $spaceId)
            ->willReturn([$sdkComment]);

        $comments = $this->gateway->getComments($spaceId, $transactionId);

        $this->assertCount(1, $comments);
        $this->assertEquals(999, $comments->all()[0]->id);
        $this->assertEquals('Test Comment', $comments->all()[0]->content);
        $this->assertEquals($now->getTimestamp(), $comments->all()[0]->createdOn->getTimestamp());
    }

    public function testGetCommentsThrowsExceptionOnError(): void
    {
        $this->sdkReferenceService->method('getPaymentTransactionsTransactionIdComments')
            ->willThrowException(new \Exception("API Error"));

        $this->logger->expects($this->once())->method('error');

        $this->expectException(TransactionCommentException::class);
        $this->gateway->getComments(1, 1);
    }
}
