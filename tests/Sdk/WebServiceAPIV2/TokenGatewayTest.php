<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TokenGateway;
use WeArePlanet\PluginCore\Token\Exception\MissingTokenException;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\Sdk\Model\CreationEntityState as SdkCreationEntityState;
use WeArePlanet\Sdk\Model\Token as SdkToken;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;
use WeArePlanet\Sdk\Service\TokensService as SdkTokensService;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;

class TokenGatewayTest extends TestCase
{
    private TokenGateway $gateway;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkTransactionsService $transactionService;
    private MockObject|SdkTokensService $tokenService;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->transactionService = $this->createMock(SdkTransactionsService::class);
        $this->tokenService = $this->createMock(SdkTokensService::class);

        $this->sdkProvider->method('getService')->willReturnMap([
            [SdkTransactionsService::class, $this->transactionService],
            [SdkTokensService::class, $this->tokenService],
        ]);

        $this->gateway = new TokenGateway($this->sdkProvider, $this->logger);
    }

    public function testCreateTokenReturnsTokenFromTransaction(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $sdkToken = new SdkToken();
        $sdkToken->setId(100);
        $sdkToken->setLinkedSpaceId($spaceId);
        $sdkToken->setVersion(1);
        $sdkToken->setState(SdkCreationEntityState::ACTIVE);
        $sdkToken->setCustomerEmailAddress('customer@example.com');
        $sdkToken->setCreatedOn(new \DateTime('2026-06-19 12:00:00'));

        $sdkTransaction = new SdkTransaction();
        $sdkTransaction->setId($transactionId);
        $sdkTransaction->setToken($sdkToken);

        // V2 Token retrieval via Transaction
        $this->transactionService->expects($this->once())
            ->method('getPaymentTransactionsId')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkTransaction);

        $result = $this->gateway->createToken($spaceId, $transactionId);

        $this->assertInstanceOf(Token::class, $result);
        $this->assertEquals(100, $result->id);
        $this->assertEquals($spaceId, $result->spaceId);
        $this->assertEquals('ACTIVE', $result->state->value);
        $this->assertEquals('customer@example.com', $result->customerIdentifier);
        $this->assertInstanceOf(\DateTimeImmutable::class, $result->createdOn);
        $this->assertEquals('2026-06-19 12:00:00', $result->createdOn->format('Y-m-d H:i:s'));
    }

    public function testCreateTokenThrowsExceptionIfTokenIsMissing(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $sdkTransaction = new SdkTransaction();
        $sdkTransaction->setId($transactionId);
        $sdkTransaction->setCustomerId('cust-1');

        $this->transactionService->expects($this->once())
            ->method('getPaymentTransactionsId')
            ->willReturn($sdkTransaction);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Token creation failed'));

        $this->expectException(MissingTokenException::class);
        $this->gateway->createToken($spaceId, $transactionId);
    }
}
