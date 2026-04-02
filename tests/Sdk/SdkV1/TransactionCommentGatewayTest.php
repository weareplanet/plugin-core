<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\SdkV1;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\SdkV1\TransactionCommentGateway;
use WeArePlanet\Sdk\Model\TransactionComment as SdkTransactionComment;
use WeArePlanet\Sdk\Service\TransactionCommentService as SdkTransactionCommentService;

class TransactionCommentGatewayTest extends TestCase
{
    private TransactionCommentGateway $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkTransactionCommentService $sdkReferenceService;

    protected function setUp(): void
    {
        $this->sdkReferenceService = $this->createMock(SdkTransactionCommentService::class);

        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->sdkProvider->method('getService')
            ->with(SdkTransactionCommentService::class)
            ->willReturn($this->sdkReferenceService);

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->gateway = new TransactionCommentGateway(
            $this->sdkProvider,
            $this->logger,
        );
    }

    public function testGetCommentsHandlesExceptionGracefully(): void
    {
        $this->sdkReferenceService->method('all')
            ->willThrowException(new \Exception("API Error"));

        $this->logger->expects($this->once())->method('error');

        $comments = $this->gateway->getComments(1, 1);
        $this->assertIsArray($comments);
        $this->assertEmpty($comments);
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

        $this->sdkReferenceService->expects($this->once())
            ->method('all')
            ->with($spaceId, $transactionId)
            ->willReturn([$sdkComment]);

        $comments = $this->gateway->getComments($spaceId, $transactionId);

        $this->assertCount(1, $comments);
        $this->assertEquals(999, $comments[0]->id);
        $this->assertEquals('Test Comment', $comments[0]->content);
        $this->assertEquals($now->getTimestamp(), $comments[0]->createdOn->getTimestamp());
    }
}
