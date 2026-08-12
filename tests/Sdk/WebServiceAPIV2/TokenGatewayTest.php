<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TokenGateway;
use WeArePlanet\PluginCore\Token\Exception\MissingTokenException;
use WeArePlanet\PluginCore\Token\Exception\TokenException;
use WeArePlanet\PluginCore\Token\State;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\TokenVersion;
use WeArePlanet\PluginCore\Token\Version\State as VersionState;
use WeArePlanet\Sdk\ApiException;
use WeArePlanet\Sdk\Model\CreationEntityState as SdkCreationEntityState;
use WeArePlanet\Sdk\Model\PaymentConnector as SdkPaymentConnector;
use WeArePlanet\Sdk\Model\PaymentConnectorConfiguration as SdkPaymentConnectorConfiguration;
use WeArePlanet\Sdk\Model\PaymentMethodConfiguration as SdkPaymentMethodConfiguration;
use WeArePlanet\Sdk\Model\PaymentMethod as SdkPaymentMethod;
use WeArePlanet\Sdk\Model\RestApiErrorResponse as SdkRestApiErrorResponse;
use WeArePlanet\Sdk\Model\Token as SdkToken;
use WeArePlanet\Sdk\Model\TokenVersion as SdkTokenVersion;
use WeArePlanet\Sdk\Model\TokenVersionState as SdkTokenVersionState;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;
use WeArePlanet\Sdk\Service\TokensService as SdkTokensService;
use WeArePlanet\Sdk\Service\TokenVersionsService as SdkTokenVersionsService;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;

class TokenGatewayTest extends TestCase
{
    private const CONNECTOR_ID = 31;
    // Deliberately distinct from CONNECTOR_ID / PAYMENT_METHOD_ID: the configuration
    // entities have their own IDs, and a mapper that confused the two would still
    // pass if the fixtures shared values.
    private const CONNECTOR_CONFIGURATION_ID = 5501;
    private const PAYMENT_METHOD_CONFIGURATION_ID = 5502;
    // The shop's own customer reference, round-tripped through the WeArePlanet Portal.
    private const CUSTOMER_ID = 'shop-customer-4711';
    private const PAYMENT_METHOD_ID = 88;
    private const SPACE_ID = 42;
    private const TOKEN_ID = 700;
    private const TOKEN_VERSION_ID = 900;
    private const TRANSACTION_ID = 1234;

    private TokenGateway $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkTokensService $tokensService;
    private MockObject|SdkTokenVersionsService $tokenVersionsService;
    private MockObject|SdkTransactionsService $transactionsService;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->tokensService = $this->createMock(SdkTokensService::class);
        $this->tokenVersionsService = $this->createMock(SdkTokenVersionsService::class);
        $this->transactionsService = $this->createMock(SdkTransactionsService::class);

        $this->sdkProvider->method('getService')
            ->willReturnMap([
                [SdkTokensService::class, $this->tokensService],
                [SdkTokenVersionsService::class, $this->tokenVersionsService],
                [SdkTransactionsService::class, $this->transactionsService],
            ]);

        $this->gateway = new TokenGateway(
            $this->sdkProvider,
            $this->logger,
        );
    }

    // ---------------------------------------------------------------------
    // createToken
    // ---------------------------------------------------------------------

    public function testCreateTokenReturnsAMappedToken(): void
    {
        $sdkTransaction = new SdkTransaction();
        $sdkTransaction->setToken($this->makeSdkToken());

        $this->transactionsService->expects($this->once())
            ->method('getPaymentTransactionsId')
            // Argument order reversed for this SDK: transaction first, space second.
            ->with(self::TRANSACTION_ID, self::SPACE_ID, ['token'])
            ->willReturn($sdkTransaction);

        $token = $this->gateway->createToken(self::SPACE_ID, self::TRANSACTION_ID);

        $this->assertInstanceOf(Token::class, $token);
        $this->assertSame(self::TOKEN_ID, $token->id);
        $this->assertSame(self::SPACE_ID, $token->spaceId);
        $this->assertSame(State::ACTIVE, $token->state);
        $this->assertTrue($token->isChargeable());

        // The two customer fields carry different values from different sources, and
        // must not be conflated: customerId is the shop's own key, customerIdentifier
        // is the display value taken from the email address.
        $this->assertSame(self::CUSTOMER_ID, $token->customerId);
        $this->assertSame('customer@example.com', $token->customerIdentifier);
    }

    public function testCreateTokenThrowsWhenTheTransactionHasNoToken(): void
    {
        $this->transactionsService->method('getPaymentTransactionsId')->willReturn(new SdkTransaction());

        $this->expectException(MissingTokenException::class);
        $this->gateway->createToken(self::SPACE_ID, self::TRANSACTION_ID);
    }

    // ---------------------------------------------------------------------
    // getTokenVersion
    // ---------------------------------------------------------------------

    public function testGetTokenVersionPassesTheSpaceAndVersionToTheSdkInTheOrderItExpects(): void
    {
        $this->tokenVersionsService->expects($this->once())
            ->method('getPaymentTokenVersionsId')
            // Argument order reversed for this SDK: version first, space second.
            ->with(self::TOKEN_VERSION_ID, self::SPACE_ID, ['token'])
            ->willReturn($this->makeSdkTokenVersion());

        $this->gateway->getTokenVersion(self::SPACE_ID, self::TOKEN_VERSION_ID);
    }

    public function testGetTokenVersionMapsTheVersionOntoTheDomainEntity(): void
    {
        $this->tokenVersionsService->method('getPaymentTokenVersionsId')->willReturn($this->makeSdkTokenVersion());

        $tokenVersion = $this->gateway->getTokenVersion(self::SPACE_ID, self::TOKEN_VERSION_ID);

        $this->assertInstanceOf(TokenVersion::class, $tokenVersion);
        $this->assertSame(self::TOKEN_VERSION_ID, $tokenVersion->id);
        $this->assertSame(VersionState::ACTIVE, $tokenVersion->state);
        $this->assertSame('Visa ****1234', $tokenVersion->name);
        $this->assertSame(self::SPACE_ID, $tokenVersion->linkedSpaceId);
        // Normalized to IDs on every API version, whatever the payload carries.
        $this->assertSame(self::CONNECTOR_ID, $tokenVersion->connectorId);
        $this->assertSame(self::PAYMENT_METHOD_ID, $tokenVersion->paymentMethodId);

        // The space-scoped configuration IDs are mapped from the configuration entity
        // itself, not from the type it points at. A plugin resolves a stored token
        // back to a merchant's configured payment method through these.
        $this->assertSame(self::CONNECTOR_CONFIGURATION_ID, $tokenVersion->connectorConfigurationId);
        $this->assertSame(self::PAYMENT_METHOD_CONFIGURATION_ID, $tokenVersion->paymentMethodConfigurationId);

        // Guard against the two kinds being conflated in either direction.
        $this->assertNotSame($tokenVersion->connectorId, $tokenVersion->connectorConfigurationId);
        $this->assertNotSame($tokenVersion->paymentMethodId, $tokenVersion->paymentMethodConfigurationId);
        // The owning token is mapped too, so a caller never needs a second lookup.
        $this->assertSame(self::TOKEN_ID, $tokenVersion->token->id);
        $this->assertSame(State::ACTIVE, $tokenVersion->token->state);
        $this->assertTrue($tokenVersion->isActive());
    }

    public function testConfigurationIdsAreNullWhenThePayloadOmitsTheConfiguration(): void
    {
        // A payload that carries no connector configuration at all: the SDK docblocks
        // declare it always present, but a read must degrade to nulls rather than fail.
        // Built through the constructor because this SDK's setter rejects null outright,
        // which would make the very case under test unrepresentable.
        $sdkTokenVersion = new SdkTokenVersion([
            'id' => self::TOKEN_VERSION_ID,
            'linked_space_id' => self::SPACE_ID,
            'name' => 'Visa ****1234',
            'state' => SdkTokenVersionState::ACTIVE,
            'token' => $this->makeSdkToken(),
        ]);

        $this->tokenVersionsService->method('getPaymentTokenVersionsId')->willReturn($sdkTokenVersion);

        $tokenVersion = $this->gateway->getTokenVersion(self::SPACE_ID, self::TOKEN_VERSION_ID);

        $this->assertInstanceOf(TokenVersion::class, $tokenVersion);
        $this->assertNull($tokenVersion->connectorId);
        $this->assertNull($tokenVersion->connectorConfigurationId);
        $this->assertNull($tokenVersion->paymentMethodConfigurationId);
        // The version itself still maps: a missing configuration is not a failed read.
        $this->assertSame(self::TOKEN_VERSION_ID, $tokenVersion->id);
    }

    public function testGetTokenVersionReturnsNullWhenTheVersionDoesNotExist(): void
    {
        $this->tokenVersionsService->method('getPaymentTokenVersionsId')
            ->willThrowException(new ApiException('Not Found', 404));

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('found no matching entity'),
                $this->callback(function (array $context): bool {
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);
                    $this->assertSame(self::TOKEN_VERSION_ID, $context['tokenVersionId']);

                    return true;
                }),
            );

        $this->assertNull($this->gateway->getTokenVersion(self::SPACE_ID, self::TOKEN_VERSION_ID));
    }

    public function testGetTokenVersionDoesNotReportAMissingVersionAsAFailure(): void
    {
        $this->tokenVersionsService->method('getPaymentTokenVersionsId')
            ->willThrowException(new ApiException('Not Found', 404));

        // An absent version is an ordinary outcome, not a failure.
        $this->logger->expects($this->never())->method('error');

        $this->assertNull($this->gateway->getTokenVersion(self::SPACE_ID, self::TOKEN_VERSION_ID));
    }

    public function testGetTokenVersionWrapsGenuineFailures(): void
    {
        $sdkException = new ApiException('Server Error', 500);
        $this->tokenVersionsService->method('getPaymentTokenVersionsId')->willThrowException($sdkException);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('failed'),
                $this->callback(function (array $context) use ($sdkException): bool {
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);
                    $this->assertSame(self::TOKEN_VERSION_ID, $context['tokenVersionId']);
                    $this->assertSame($sdkException, $context['exception']);

                    return true;
                }),
            );

        $this->expectException(TokenException::class);
        $this->gateway->getTokenVersion(self::SPACE_ID, self::TOKEN_VERSION_ID);
    }

    public function testAnUnknownVersionStateIsReportedAsUninitializedAndLogged(): void
    {
        $this->tokenVersionsService->method('getPaymentTokenVersionsId')
            ->willReturn($this->makeSdkTokenVersion('SOMETHING_NEW'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Unknown token version state'),
                $this->callback(function (array $context): bool {
                    $this->assertSame('SOMETHING_NEW', $context['state']);

                    return true;
                }),
            );

        $tokenVersion = $this->gateway->getTokenVersion(self::SPACE_ID, self::TOKEN_VERSION_ID);

        $this->assertInstanceOf(TokenVersion::class, $tokenVersion);
        $this->assertSame(VersionState::UNINITIALIZED, $tokenVersion->state);
    }

    public function testAVersionWithoutItsTokenIsRejectedInsteadOfFabricated(): void
    {
        // A payload that never carried the token, e.g. because it was not expanded.
        $sdkTokenVersion = new SdkTokenVersion();
        $sdkTokenVersion->setId(self::TOKEN_VERSION_ID);
        $sdkTokenVersion->setState(SdkTokenVersionState::ACTIVE);

        $this->tokenVersionsService->method('getPaymentTokenVersionsId')->willReturn($sdkTokenVersion);

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('unexpected response'));

        $this->expectException(TokenException::class);
        $this->gateway->getTokenVersion(self::SPACE_ID, self::TOKEN_VERSION_ID);
    }

    // ---------------------------------------------------------------------
    // getActiveTokenVersion
    // ---------------------------------------------------------------------

    public function testGetActiveTokenVersionPassesTheSpaceAndTokenToTheSdkInTheOrderItExpects(): void
    {
        $this->tokensService->expects($this->once())
            ->method('getPaymentTokensIdActiveVersion')
            // Argument order reversed for this SDK: token first, space second.
            ->with(self::TOKEN_ID, self::SPACE_ID, ['token'])
            ->willReturn($this->makeSdkTokenVersion());

        $this->gateway->getActiveTokenVersion(self::SPACE_ID, self::TOKEN_ID);
    }

    public function testGetActiveTokenVersionMapsTheVersionOntoTheDomainEntity(): void
    {
        $this->tokensService->method('getPaymentTokensIdActiveVersion')->willReturn($this->makeSdkTokenVersion());

        $tokenVersion = $this->gateway->getActiveTokenVersion(self::SPACE_ID, self::TOKEN_ID);

        $this->assertInstanceOf(TokenVersion::class, $tokenVersion);
        $this->assertSame(self::TOKEN_VERSION_ID, $tokenVersion->id);
        $this->assertSame(self::TOKEN_ID, $tokenVersion->token->id);
        $this->assertTrue($tokenVersion->isActive());
    }

    public function testGetActiveTokenVersionReturnsNullWhenNoVersionIsActive(): void
    {
        $this->tokensService->method('getPaymentTokensIdActiveVersion')
            ->willThrowException(new ApiException('Not Found', 404));

        $this->assertNull($this->gateway->getActiveTokenVersion(self::SPACE_ID, self::TOKEN_ID));
    }

    public function testGetActiveTokenVersionReportsAnObsoleteVersionAsInactive(): void
    {
        $this->tokensService->method('getPaymentTokensIdActiveVersion')
            ->willReturn($this->makeSdkTokenVersion(SdkTokenVersionState::OBSOLETE));

        $tokenVersion = $this->gateway->getActiveTokenVersion(self::SPACE_ID, self::TOKEN_ID);

        $this->assertInstanceOf(TokenVersion::class, $tokenVersion);
        $this->assertSame(VersionState::OBSOLETE, $tokenVersion->state);
        $this->assertFalse($tokenVersion->isActive());
    }

    // ---------------------------------------------------------------------
    // deleteToken
    // ---------------------------------------------------------------------

    public function testDeleteTokenPassesTheSpaceAndTokenToTheSdkInTheOrderItExpects(): void
    {
        $this->tokensService->expects($this->once())
            ->method('deletePaymentTokensId')
            // Argument order reversed for this SDK: token first, space second.
            ->with(self::TOKEN_ID, self::SPACE_ID);

        $this->gateway->deleteToken(self::SPACE_ID, self::TOKEN_ID);
    }

    public function testDeleteTokenLogsSuccessWithStructuredContext(): void
    {
        $this->tokensService->method('deletePaymentTokensId');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('succeeded'),
                $this->callback(function (array $context): bool {
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);
                    $this->assertSame(self::TOKEN_ID, $context['tokenId']);
                    $this->assertArrayHasKey('operation', $context);

                    return true;
                }),
            );

        $this->gateway->deleteToken(self::SPACE_ID, self::TOKEN_ID);
    }

    public function testDeleteTokenWrapsSdkFailures(): void
    {
        $sdkException = new \Exception('SDK unavailable');
        $this->tokensService->method('deletePaymentTokensId')->willThrowException($sdkException);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('failed'),
                $this->callback(function (array $context) use ($sdkException): bool {
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);
                    $this->assertSame(self::TOKEN_ID, $context['tokenId']);
                    $this->assertSame('SDK unavailable', $context['errorMessage']);
                    $this->assertSame($sdkException, $context['exception']);

                    return true;
                }),
            );

        try {
            $this->gateway->deleteToken(self::SPACE_ID, self::TOKEN_ID);
            $this->fail('Expected a TokenException.');
        } catch (TokenException $e) {
            $this->assertSame($sdkException, $e->getPrevious());
            $this->assertStringContainsString((string)self::TOKEN_ID, $e->getMessage());
            $this->assertStringContainsString((string)self::SPACE_ID, $e->getMessage());
        }
    }

    // ---------------------------------------------------------------------
    // Error-response union (no WebServiceAPIV1 counterpart: that SDK always throws)
    // ---------------------------------------------------------------------

    public function testAnErrorResponseOnALookupIsTurnedIntoAFailureCarryingItsDetails(): void
    {
        $this->tokenVersionsService->method('getPaymentTokenVersionsId')->willReturn($this->makeErrorResponse());

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('unexpected response'),
                $this->callback(function (array $context): bool {
                    $this->assertSame('Token version is not readable.', $context['errorMessage']);
                    $this->assertSame('FORBIDDEN', $context['errorCode']);
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);
                    $this->assertSame(self::TOKEN_VERSION_ID, $context['tokenVersionId']);

                    return true;
                }),
            );

        $this->expectException(TokenException::class);
        $this->gateway->getTokenVersion(self::SPACE_ID, self::TOKEN_VERSION_ID);
    }

    public function testAnErrorResponseIsNeverMistakenForAnAbsentEntity(): void
    {
        $this->tokensService->method('getPaymentTokensIdActiveVersion')->willReturn($this->makeErrorResponse());

        // A reported failure must surface, not be folded into a null "no active version".
        $this->expectException(TokenException::class);
        $this->gateway->getActiveTokenVersion(self::SPACE_ID, self::TOKEN_ID);
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    private function makeErrorResponse(): SdkRestApiErrorResponse
    {
        $errorResponse = new SdkRestApiErrorResponse();
        $errorResponse->setMessage('Token version is not readable.');
        $errorResponse->setCode('FORBIDDEN');

        return $errorResponse;
    }

    private function makeSdkToken(): SdkToken
    {
        $sdkToken = new SdkToken();
        $sdkToken->setId(self::TOKEN_ID);
        $sdkToken->setLinkedSpaceId(self::SPACE_ID);
        $sdkToken->setState(SdkCreationEntityState::ACTIVE);
        $sdkToken->setCustomerId(self::CUSTOMER_ID);
        $sdkToken->setCustomerEmailAddress('customer@example.com');

        return $sdkToken;
    }

    private function makeSdkTokenVersion(string $state = SdkTokenVersionState::ACTIVE): SdkTokenVersion
    {
        // This API models the connector as an object rather than a bare ID.
        $sdkConnector = new SdkPaymentConnector();
        $sdkConnector->setId(self::CONNECTOR_ID);

        $paymentMethodConfiguration = new SdkPaymentMethodConfiguration();
        $paymentMethodConfiguration->setId(self::PAYMENT_METHOD_CONFIGURATION_ID);

        $connectorConfiguration = new SdkPaymentConnectorConfiguration();
        $connectorConfiguration->setConnector($sdkConnector);
        // The configuration's own ID, distinct from the connector type above.
        $connectorConfiguration->setId(self::CONNECTOR_CONFIGURATION_ID);
        $connectorConfiguration->setPaymentMethodConfiguration($paymentMethodConfiguration);

        // This API embeds the payment method rather than reporting a bare ID.
        $sdkPaymentMethod = new SdkPaymentMethod();
        $sdkPaymentMethod->setId(self::PAYMENT_METHOD_ID);

        $sdkTokenVersion = new SdkTokenVersion();
        $sdkTokenVersion->setId(self::TOKEN_VERSION_ID);
        $sdkTokenVersion->setLinkedSpaceId(self::SPACE_ID);
        $sdkTokenVersion->setName('Visa ****1234');
        $sdkTokenVersion->setState($state);
        $sdkTokenVersion->setToken($this->makeSdkToken());
        $sdkTokenVersion->setPaymentConnectorConfiguration($connectorConfiguration);
        $sdkTokenVersion->setPaymentMethod($sdkPaymentMethod);

        return $sdkTokenVersion;
    }
}
