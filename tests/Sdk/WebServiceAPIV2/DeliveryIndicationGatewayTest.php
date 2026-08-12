<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\DeliveryIndication\DeliveryIndication;
use WeArePlanet\PluginCore\DeliveryIndication\Exception\DeliveryIndicationException;
use WeArePlanet\PluginCore\DeliveryIndication\State;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\DeliveryIndicationGateway;
use WeArePlanet\Sdk\Model\DeliveryIndication as SdkDeliveryIndication;
use WeArePlanet\Sdk\Model\DeliveryIndicationSearchResponse as SdkDeliveryIndicationSearchResponse;
use WeArePlanet\Sdk\Model\DeliveryIndicationState as SdkDeliveryIndicationState;
use WeArePlanet\Sdk\Model\RestApiErrorResponse as SdkRestApiErrorResponse;
use WeArePlanet\Sdk\Model\TransactionCompletion as SdkTransactionCompletion;
use WeArePlanet\Sdk\Service\DeliveryIndicationsService as SdkDeliveryIndicationsService;

class DeliveryIndicationGatewayTest extends TestCase
{
    private const COMPLETION_ID = 555;
    private const INDICATION_ID = 987;
    private const SPACE_ID = 42;
    private const TRANSACTION_ID = 1234;

    private DeliveryIndicationGateway $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkDeliveryIndicationsService $service;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = $this->createMock(SdkDeliveryIndicationsService::class);

        $this->sdkProvider->method('getService')
            ->with(SdkDeliveryIndicationsService::class)
            ->willReturn($this->service);

        $this->gateway = new DeliveryIndicationGateway(
            $this->sdkProvider,
            $this->logger,
        );
    }

    // ---------------------------------------------------------------------
    // Parameter order
    // ---------------------------------------------------------------------

    public function testFindByTransactionBuildsTheSearchTheSdkExpects(): void
    {
        $this->service->expects($this->once())
            ->method('getPaymentDeliveryIndicationsSearch')
            ->with(
                // Search takes the space first, unlike this SDK's single-entity operations.
                self::SPACE_ID,
                null,
                1,
                null,
                null,
                'transaction.id:' . self::TRANSACTION_ID,
            )
            ->willReturn($this->makeSearchResponse([$this->makeSdkIndication()]));

        $this->gateway->findByTransaction(self::SPACE_ID, self::TRANSACTION_ID);
    }

    public function testFindByTransactionMapsTheFirstResult(): void
    {
        $this->service->method('getPaymentDeliveryIndicationsSearch')
            ->willReturn($this->makeSearchResponse([$this->makeSdkIndication()]));

        $indication = $this->gateway->findByTransaction(self::SPACE_ID, self::TRANSACTION_ID);

        $this->assertInstanceOf(DeliveryIndication::class, $indication);
        $this->assertSame(self::INDICATION_ID, $indication->id);
        $this->assertSame(self::SPACE_ID, $indication->spaceId);
        $this->assertSame(self::TRANSACTION_ID, $indication->transactionId);
        $this->assertSame(self::COMPLETION_ID, $indication->completionId);
        $this->assertSame(State::PENDING, $indication->state);
    }

    public function testFindByTransactionReturnsNullWhenTheTransactionHasNoIndication(): void
    {
        $this->service->method('getPaymentDeliveryIndicationsSearch')
            ->willReturn($this->makeSearchResponse([]));

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('No delivery indication found'),
                $this->callback(function (array $context): bool {
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);
                    $this->assertSame(self::TRANSACTION_ID, $context['transactionId']);

                    return true;
                }),
            );

        $this->assertNull($this->gateway->findByTransaction(self::SPACE_ID, self::TRANSACTION_ID));
    }

    public function testFindByTransactionDoesNotThrowWhenNothingIsFound(): void
    {
        $this->service->method('getPaymentDeliveryIndicationsSearch')
            ->willReturn($this->makeSearchResponse([]));

        // An absent indication is an ordinary outcome, not a failure.
        $this->logger->expects($this->never())->method('error');

        $this->assertNull($this->gateway->findByTransaction(self::SPACE_ID, self::TRANSACTION_ID));
    }

    public function testFindByTransactionLogsStructuredContextAndWrapsSearchFailures(): void
    {
        $sdkException = new \Exception('SDK unavailable');
        $this->service->method('getPaymentDeliveryIndicationsSearch')->willThrowException($sdkException);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('failed'),
                $this->callback(function (array $context) use ($sdkException): bool {
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);
                    $this->assertSame(self::TRANSACTION_ID, $context['transactionId']);
                    $this->assertSame('SDK unavailable', $context['errorMessage']);
                    $this->assertSame($sdkException, $context['exception']);

                    return true;
                }),
            );

        $this->expectException(DeliveryIndicationException::class);
        $this->gateway->findByTransaction(self::SPACE_ID, self::TRANSACTION_ID);
    }

    /**
     * A search can answer with an error model too, so the union has to be resolved on
     * this path as well. WebServiceAPIV1 has no equivalent model, hence no counterpart
     * test there.
     */
    public function testFindByTransactionTurnsAnErrorResponseIntoAFailureCarryingItsDetails(): void
    {
        $errorResponse = new SdkRestApiErrorResponse();
        $errorResponse->setMessage('Malformed search query.');
        $errorResponse->setCode('BAD_REQUEST');

        $this->service->method('getPaymentDeliveryIndicationsSearch')->willReturn($errorResponse);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('unexpected response'),
                $this->callback(function (array $context): bool {
                    $this->assertSame('Malformed search query.', $context['errorMessage']);
                    $this->assertSame('BAD_REQUEST', $context['errorCode']);
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);
                    $this->assertSame(self::TRANSACTION_ID, $context['transactionId']);

                    return true;
                }),
            );

        $this->expectException(DeliveryIndicationException::class);
        $this->gateway->findByTransaction(self::SPACE_ID, self::TRANSACTION_ID);
    }

    public function testGetPassesTheSpaceAndIndicationToTheSdkInTheOrderItExpects(): void
    {
        $this->expectSdkCall('getPaymentDeliveryIndicationsId')->willReturn($this->makeSdkIndication());

        $this->gateway->get(self::SPACE_ID, self::INDICATION_ID);
    }

    public function testMarkAsSuitablePassesTheSpaceAndIndicationToTheSdkInTheOrderItExpects(): void
    {
        $this->expectSdkCall('postPaymentDeliveryIndicationsIdMarkSuitable')
            ->willReturn($this->makeSdkIndication(SdkDeliveryIndicationState::SUITABLE));

        $this->gateway->markAsSuitable(self::SPACE_ID, self::INDICATION_ID);
    }

    public function testMarkAsNotSuitablePassesTheSpaceAndIndicationToTheSdkInTheOrderItExpects(): void
    {
        $this->expectSdkCall('postPaymentDeliveryIndicationsIdMarkNotSuitable')
            ->willReturn($this->makeSdkIndication(SdkDeliveryIndicationState::NOT_SUITABLE));

        $this->gateway->markAsNotSuitable(self::SPACE_ID, self::INDICATION_ID);
    }

    // ---------------------------------------------------------------------
    // Mapping
    // ---------------------------------------------------------------------

    public function testGetMapsTheSdkIndicationOntoTheDomainEntity(): void
    {
        $this->service->method('getPaymentDeliveryIndicationsId')->willReturn($this->makeSdkIndication());

        $indication = $this->gateway->get(self::SPACE_ID, self::INDICATION_ID);

        $this->assertInstanceOf(DeliveryIndication::class, $indication);
        $this->assertSame(self::INDICATION_ID, $indication->id);
        $this->assertSame(self::SPACE_ID, $indication->spaceId);
        $this->assertSame(State::PENDING, $indication->state);
        $this->assertSame(self::TRANSACTION_ID, $indication->transactionId);
        // Normalized to an ID on every API version, whatever the payload carries.
        $this->assertSame(self::COMPLETION_ID, $indication->completionId);
    }

    public function testMarkAsSuitableReturnsTheDecidedIndication(): void
    {
        $this->service->method('postPaymentDeliveryIndicationsIdMarkSuitable')
            ->willReturn($this->makeSdkIndication(SdkDeliveryIndicationState::SUITABLE));

        $indication = $this->gateway->markAsSuitable(self::SPACE_ID, self::INDICATION_ID);

        $this->assertSame(State::SUITABLE, $indication->state);
        $this->assertFalse($indication->isDecisionPending());
    }

    public function testMarkAsNotSuitableReturnsTheDecidedIndication(): void
    {
        $this->service->method('postPaymentDeliveryIndicationsIdMarkNotSuitable')
            ->willReturn($this->makeSdkIndication(SdkDeliveryIndicationState::NOT_SUITABLE));

        $indication = $this->gateway->markAsNotSuitable(self::SPACE_ID, self::INDICATION_ID);

        $this->assertSame(State::NOT_SUITABLE, $indication->state);
        $this->assertFalse($indication->isDecisionPending());
    }

    public function testAnIndicationAwaitingAManualCheckIsStillDecidable(): void
    {
        $this->service->method('getPaymentDeliveryIndicationsId')
            ->willReturn($this->makeSdkIndication(SdkDeliveryIndicationState::MANUAL_CHECK_REQUIRED));

        $indication = $this->gateway->get(self::SPACE_ID, self::INDICATION_ID);

        $this->assertSame(State::MANUAL_CHECK_REQUIRED, $indication->state);
        $this->assertTrue($indication->isDecisionPending());
    }

    public function testGetFallsBackToTheRequestedSpaceAndNullReferencesOnASparsePayload(): void
    {
        $sdkIndication = new SdkDeliveryIndication();
        $sdkIndication->setId(self::INDICATION_ID);
        $sdkIndication->setState(SdkDeliveryIndicationState::PENDING);

        $this->service->method('getPaymentDeliveryIndicationsId')->willReturn($sdkIndication);

        $indication = $this->gateway->get(self::SPACE_ID, self::INDICATION_ID);

        $this->assertSame(self::SPACE_ID, $indication->spaceId);
        $this->assertNull($indication->transactionId);
        $this->assertNull($indication->completionId);
    }

    public function testAnUnknownStateIsReportedAsPendingAndLogged(): void
    {
        $this->service->method('getPaymentDeliveryIndicationsId')
            ->willReturn($this->makeSdkIndication('SOMETHING_NEW'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Unknown delivery indication state'),
                $this->callback(function (array $context): bool {
                    $this->assertSame('SOMETHING_NEW', $context['state']);
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);

                    return true;
                }),
            );

        $indication = $this->gateway->get(self::SPACE_ID, self::INDICATION_ID);

        $this->assertSame(State::PENDING, $indication->state);
    }

    // ---------------------------------------------------------------------
    // Observability
    // ---------------------------------------------------------------------

    public function testASuccessfulOperationIsLoggedWithStructuredContext(): void
    {
        $this->service->method('postPaymentDeliveryIndicationsIdMarkSuitable')
            ->willReturn($this->makeSdkIndication(SdkDeliveryIndicationState::SUITABLE));

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('succeeded'),
                $this->callback(function (array $context): bool {
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);
                    $this->assertSame(self::INDICATION_ID, $context['indicationId']);
                    $this->assertSame(State::SUITABLE->value, $context['state']);
                    $this->assertArrayHasKey('operation', $context);

                    return true;
                }),
            );

        $this->gateway->markAsSuitable(self::SPACE_ID, self::INDICATION_ID);
    }

    public function testAFailureIsLoggedWithStructuredContextAndWrapped(): void
    {
        $sdkException = new \Exception('SDK unavailable');
        $this->service->method('postPaymentDeliveryIndicationsIdMarkSuitable')->willThrowException($sdkException);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('failed'),
                $this->callback(function (array $context) use ($sdkException): bool {
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);
                    $this->assertSame(self::INDICATION_ID, $context['indicationId']);
                    $this->assertSame('SDK unavailable', $context['errorMessage']);
                    $this->assertSame($sdkException, $context['exception']);

                    return true;
                }),
            );

        $this->expectException(DeliveryIndicationException::class);
        $this->gateway->markAsSuitable(self::SPACE_ID, self::INDICATION_ID);
    }

    public function testTheWrappedFailureKeepsTheSdkExceptionAsPrevious(): void
    {
        $sdkException = new \Exception('SDK unavailable');
        $this->service->method('getPaymentDeliveryIndicationsId')->willThrowException($sdkException);

        try {
            $this->gateway->get(self::SPACE_ID, self::INDICATION_ID);
            $this->fail('Expected a DeliveryIndicationException.');
        } catch (DeliveryIndicationException $e) {
            $this->assertSame($sdkException, $e->getPrevious());
            $this->assertStringContainsString((string)self::INDICATION_ID, $e->getMessage());
            $this->assertStringContainsString((string)self::SPACE_ID, $e->getMessage());
        }
    }

    public function testAResponseThatIsNotADeliveryIndicationFailsInsteadOfLeakingOut(): void
    {
        $this->service->method('getPaymentDeliveryIndicationsId')->willReturn(null);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('unexpected response'),
                $this->callback(function (array $context): bool {
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);
                    $this->assertSame(self::INDICATION_ID, $context['indicationId']);
                    $this->assertArrayHasKey('responseType', $context);

                    return true;
                }),
            );

        $this->expectException(DeliveryIndicationException::class);
        $this->gateway->get(self::SPACE_ID, self::INDICATION_ID);
    }

    /**
     * This SDK answers some non-2xx replies with an error model rather than throwing,
     * so the union return type has to be resolved inside the gateway. WebServiceAPIV1
     * has no equivalent model, hence no counterpart test there.
     */
    public function testAnErrorResponseIsTurnedIntoAFailureCarryingItsDetails(): void
    {
        $errorResponse = new SdkRestApiErrorResponse();
        $errorResponse->setMessage('Delivery indication already decided.');
        $errorResponse->setCode('CONFLICT');

        $this->service->method('postPaymentDeliveryIndicationsIdMarkSuitable')->willReturn($errorResponse);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('unexpected response'),
                $this->callback(function (array $context): bool {
                    $this->assertSame('Delivery indication already decided.', $context['errorMessage']);
                    $this->assertSame('CONFLICT', $context['errorCode']);
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);
                    $this->assertSame(self::INDICATION_ID, $context['indicationId']);

                    return true;
                }),
            );

        $this->expectException(DeliveryIndicationException::class);
        $this->gateway->markAsSuitable(self::SPACE_ID, self::INDICATION_ID);
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    /**
     * Expects one call of the given SDK operation, asserting the argument order this
     * SDK requires: the indication first, then the space — the reverse of the domain
     * interface's order.
     */
    private function expectSdkCall(string $method): \PHPUnit\Framework\MockObject\Builder\InvocationMocker
    {
        return $this->service->expects($this->once())
            ->method($method)
            ->with(self::INDICATION_ID, self::SPACE_ID);
    }

    /**
     * @param list<SdkDeliveryIndication> $indications
     */
    private function makeSearchResponse(array $indications): SdkDeliveryIndicationSearchResponse
    {
        $response = new SdkDeliveryIndicationSearchResponse();
        $response->setData($indications);

        return $response;
    }

    private function makeSdkIndication(string $state = SdkDeliveryIndicationState::PENDING): SdkDeliveryIndication
    {
        $sdkIndication = new SdkDeliveryIndication();
        $sdkIndication->setId(self::INDICATION_ID);
        $sdkIndication->setLinkedSpaceId(self::SPACE_ID);
        $sdkIndication->setLinkedTransaction(self::TRANSACTION_ID);
        $sdkIndication->setState($state);

        // This API embeds the whole completion rather than reporting a bare ID.
        $sdkCompletion = new SdkTransactionCompletion();
        $sdkCompletion->setId(self::COMPLETION_ID);
        $sdkIndication->setCompletion($sdkCompletion);

        return $sdkIndication;
    }
}
