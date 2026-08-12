<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Token;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Token\Exception\TokenException;
use WeArePlanet\PluginCore\Token\State;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\TokenGatewayInterface;
use WeArePlanet\PluginCore\Token\TokenService;
use WeArePlanet\PluginCore\Token\TokenVersion;
use WeArePlanet\PluginCore\Token\Version\State as VersionState;
use WeArePlanet\Sdk\ApiException;

class TokenServiceTest extends TestCase
{
    private MockObject|TokenGatewayInterface $gateway;
    private MockObject|LoggerInterface $logger;
    private TokenService $service;

    private function makeTokenVersion(): TokenVersion
    {
        return new TokenVersion(
            id: 900,
            token: new Token(id: 700, state: State::ACTIVE, spaceId: 1),
            state: VersionState::ACTIVE,
            name: 'Visa ****1234',
            linkedSpaceId: 1,
            connectorId: 31,
            paymentMethodId: 88,
        );
    }

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(TokenGatewayInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new TokenService($this->gateway, $this->logger);
    }

    public function testCreateTokenForTransactionPassesTheGatewayFailureThrough(): void
    {
        $gatewayException = new TokenException(
            'Failed to create token for transaction 2: Card declined by issuer',
            new LocalizedString('Token creation failed. Please try again or contact support.'),
            new ApiException('Card declined by issuer'),
        );

        $this->gateway->expects($this->once())
            ->method('createToken')
            ->willThrowException($gatewayException);

        try {
            $this->service->createTokenForTransaction(1, 2);
            $this->fail('Expected TokenException to be thrown.');
        } catch (TokenException $e) {
            // The gateway already produced a domain exception carrying a localized
            // message and the original cause; the service must not re-wrap it.
            $this->assertSame($gatewayException, $e);
            $this->assertInstanceOf(LocalizedString::class, $e->getLocalizedMessage());
            $this->assertSame(
                'Token creation failed. Please try again or contact support.',
                $e->getLocalizedMessage()->localize('en-US'),
            );
            $this->assertInstanceOf(ApiException::class, $e->getPrevious());
        }
    }

    public function testCreateTokenForTransactionReturnsToken(): void
    {
        $token = new Token(id: 100, state: State::ACTIVE);

        $this->gateway->expects($this->once())
            ->method('createToken')
            ->with(1, 2)
            ->willReturn($token);

        $result = $this->service->createTokenForTransaction(1, 2);

        $this->assertSame($token, $result);
    }

    public function testDeleteTokenDelegatesToTheGateway(): void
    {
        $this->gateway->expects($this->once())
            ->method('deleteToken')
            ->with(1, 700);

        $this->service->deleteToken(1, 700);
    }

    public function testDeleteTokenPassesTheGatewayFailureThrough(): void
    {
        $gatewayException = new TokenException('Token operation delete failed for spaceId=1, tokenId=700: boom');

        $this->gateway->expects($this->once())
            ->method('deleteToken')
            ->willThrowException($gatewayException);

        try {
            $this->service->deleteToken(1, 700);
            $this->fail('Expected TokenException to be thrown.');
        } catch (TokenException $e) {
            $this->assertSame($gatewayException, $e);
        }
    }

    public function testGetActiveTokenVersionDelegatesToTheGateway(): void
    {
        $tokenVersion = $this->makeTokenVersion();

        $this->gateway->expects($this->once())
            ->method('getActiveTokenVersion')
            ->with(1, 700)
            ->willReturn($tokenVersion);

        $this->assertSame($tokenVersion, $this->service->getActiveTokenVersion(1, 700));
    }

    public function testGetActiveTokenVersionReturnsNullWhenNoVersionIsActive(): void
    {
        $this->gateway->expects($this->once())
            ->method('getActiveTokenVersion')
            ->with(1, 700)
            ->willReturn(null);

        $this->assertNull($this->service->getActiveTokenVersion(1, 700));
    }

    public function testGetTokenVersionDelegatesToTheGateway(): void
    {
        $tokenVersion = $this->makeTokenVersion();

        $this->gateway->expects($this->once())
            ->method('getTokenVersion')
            ->with(1, 900)
            ->willReturn($tokenVersion);

        $this->assertSame($tokenVersion, $this->service->getTokenVersion(1, 900));
    }

    public function testGetTokenVersionReturnsNullWhenTheVersionDoesNotExist(): void
    {
        $this->gateway->expects($this->once())
            ->method('getTokenVersion')
            ->with(1, 900)
            ->willReturn(null);

        $this->assertNull($this->service->getTokenVersion(1, 900));
    }
}
