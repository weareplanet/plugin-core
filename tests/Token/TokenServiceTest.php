<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Token;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Token\Exception\TokenException;
use WeArePlanet\PluginCore\Token\State;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\TokenGatewayInterface;
use WeArePlanet\PluginCore\Token\TokenService;
use WeArePlanet\Sdk\ApiException;

class TokenServiceTest extends TestCase
{
    private MockObject|TokenGatewayInterface $gateway;
    private MockObject|LoggerInterface $logger;
    private TokenService $service;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(TokenGatewayInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new TokenService($this->gateway, $this->logger);
    }

    public function testCreateTokenForTransactionReturnsToken(): void
    {
        $token = new Token();
        $token->id = 100;
        $token->state = State::ACTIVE;

        $this->gateway->expects($this->once())
            ->method('createToken')
            ->with(1, 2)
            ->willReturn($token);

        $result = $this->service->createTokenForTransaction(1, 2);

        $this->assertSame($token, $result);
    }

    public function testCreateTokenForTransactionThrowsLocalizedExceptionOnApiFailure(): void
    {
        $this->gateway->expects($this->once())
            ->method('createToken')
            ->willThrowException(new ApiException('Card declined by issuer'));

        try {
            $this->service->createTokenForTransaction(1, 2);
            $this->fail('Expected TokenException to be thrown.');
        } catch (TokenException $e) {
            $this->assertInstanceOf(LocalizedString::class, $e->getLocalizedMessage());
            $this->assertSame('Token creation failed. Please try again or contact support.', $e->getLocalizedMessage()->localize('en-US'));
            $this->assertInstanceOf(ApiException::class, $e->getPrevious());
        }
    }

    public function testCreateTokenForTransactionThrowsLocalizedExceptionOnUnexpectedError(): void
    {
        $this->gateway->expects($this->once())
            ->method('createToken')
            ->willThrowException(new \RuntimeException('Unexpected boom'));

        $this->expectException(TokenException::class);

        $this->service->createTokenForTransaction(1, 2);
    }
}
