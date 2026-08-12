<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV1;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\DeliveryIndication\DeliveryIndication;
use WeArePlanet\PluginCore\DeliveryIndication\Exception\DeliveryIndicationException;
use WeArePlanet\PluginCore\DeliveryIndication\State;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\DeliveryIndicationGateway;
use WeArePlanet\Sdk\Model\CriteriaOperator as SdkCriteriaOperator;
use WeArePlanet\Sdk\Model\DeliveryIndication as SdkDeliveryIndication;
use WeArePlanet\Sdk\Model\DeliveryIndicationState as SdkDeliveryIndicationState;
use WeArePlanet\Sdk\Model\EntityQuery as SdkEntityQuery;
use WeArePlanet\Sdk\Model\EntityQueryFilter as SdkEntityQueryFilter;
use WeArePlanet\Sdk\Model\EntityQueryFilterType as SdkEntityQueryFilterType;
use WeArePlanet\Sdk\Service\DeliveryIndicationService as SdkDeliveryIndicationService;

class DeliveryIndicationGatewayTest extends TestCase
{
    private const COMPLETION_ID = 555;
    private const INDICATION_ID = 987;
    private const SPACE_ID = 42;
    private const TRANSACTION_ID = 1234;

    private DeliveryIndicationGateway $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkDeliveryIndicationService $service;

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    /**
     * Expects one call of the given SDK operation, asserting the argument order this
     * SDK requires: the space first, then the indication.
     */
    private function expectSdkCall(string $method): \PHPUnit\Framework\MockObject\Builder\InvocationMocker
    {
        return $this->service->expects($this->once())
            ->method($method)
            ->with(self::SPACE_ID, self::INDICATION_ID);
    }

    private function makeSdkIndication(string $state = SdkDeliveryIndicationState::PENDING): SdkDeliveryIndication
    {
        $sdkIndication = new SdkDeliveryIndication();
        $sdkIndication->setId(self::INDICATION_ID);
        $sdkIndication->setLinkedSpaceId(self::SPACE_ID);
        $sdkIndication->setLinkedTransaction(self::TRANSACTION_ID);
        $sdkIndication->setState($state);
        // This API reports the completion as a bare ID.
        $sdkIndication->setCompletion(self::COMPLETION_ID);

        return $sdkIndication;
    }

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = $this->createMock(SdkDeliveryIndicationService::class);

        $this->sdkProvider->method('getService')
            ->with(SdkDeliveryIndicationService::class)
            ->willReturn($this->service);

        $this->gateway = new DeliveryIndicationGateway(
            $this->sdkProvider,
            $this->logger,
        );
    }

    public function testAFailureIsLoggedWithStructuredContextAndWrapped(): void
    {
        $sdkException = new \Exception('SDK unavailable');
        $this->service->method('markAsSuitable')->willThrowException($sdkException);

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

    public function testAnIndicationAwaitingAManualCheckIsStillDecidable(): void
    {
        $this->service->method('read')
            ->willReturn($this->makeSdkIndication(SdkDeliveryIndicationState::MANUAL_CHECK_REQUIRED));

        $indication = $this->gateway->get(self::SPACE_ID, self::INDICATION_ID);

        $this->assertSame(State::MANUAL_CHECK_REQUIRED, $indication->state);
        $this->assertTrue($indication->isDecisionPending());
    }

    public function testAnUnknownStateIsReportedAsPendingAndLogged(): void
    {
        $this->service->method('read')->willReturn($this->makeSdkIndication('SOMETHING_NEW'));

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

    public function testAResponseThatIsNotADeliveryIndicationFailsInsteadOfLeakingOut(): void
    {
        $this->service->method('read')->willReturn(null);

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

    // ---------------------------------------------------------------------
    // Observability
    // ---------------------------------------------------------------------

    public function testASuccessfulOperationIsLoggedWithStructuredContext(): void
    {
        $this->service->method('markAsSuitable')
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

    // ---------------------------------------------------------------------
    // Parameter order
    // ---------------------------------------------------------------------

    public function testFindByTransactionBuildsTheSearchTheSdkExpects(): void
    {
        $this->service->expects($this->once())
            ->method('search')
            ->with(
                self::SPACE_ID,
                $this->callback(function (SdkEntityQuery $query): bool {
                    $this->assertSame(1, $query->getNumberOfEntities());

                    $filter = $query->getFilter();
                    $this->assertInstanceOf(SdkEntityQueryFilter::class, $filter);
                    $this->assertSame(SdkEntityQueryFilterType::LEAF, $filter->getType());
                    $this->assertSame(SdkCriteriaOperator::EQUALS, $filter->getOperator());
                    $this->assertSame('transaction.id', $filter->getFieldName());
                    $this->assertSame(self::TRANSACTION_ID, $filter->getValue());

                    return true;
                }),
            )
            ->willReturn([$this->makeSdkIndication()]);

        $this->gateway->findByTransaction(self::SPACE_ID, self::TRANSACTION_ID);
    }

    public function testFindByTransactionDoesNotThrowWhenNothingIsFound(): void
    {
        $this->service->method('search')->willReturn([]);

        // An absent indication is an ordinary outcome, not a failure.
        $this->logger->expects($this->never())->method('error');

        $this->assertNull($this->gateway->findByTransaction(self::SPACE_ID, self::TRANSACTION_ID));
    }

    public function testFindByTransactionLogsStructuredContextAndWrapsSearchFailures(): void
    {
        $sdkException = new \Exception('SDK unavailable');
        $this->service->method('search')->willThrowException($sdkException);

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

    public function testFindByTransactionMapsTheFirstResult(): void
    {
        $this->service->method('search')->willReturn([$this->makeSdkIndication()]);

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
        $this->service->method('search')->willReturn([]);

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

    public function testGetFallsBackToTheRequestedSpaceAndNullReferencesOnASparsePayload(): void
    {
        $sdkIndication = new SdkDeliveryIndication();
        $sdkIndication->setId(self::INDICATION_ID);
        $sdkIndication->setState(SdkDeliveryIndicationState::PENDING);

        $this->service->method('read')->willReturn($sdkIndication);

        $indication = $this->gateway->get(self::SPACE_ID, self::INDICATION_ID);

        $this->assertSame(self::SPACE_ID, $indication->spaceId);
        $this->assertNull($indication->transactionId);
        $this->assertNull($indication->completionId);
    }

    // ---------------------------------------------------------------------
    // Mapping
    // ---------------------------------------------------------------------

    public function testGetMapsTheSdkIndicationOntoTheDomainEntity(): void
    {
        $this->service->method('read')->willReturn($this->makeSdkIndication());

        $indication = $this->gateway->get(self::SPACE_ID, self::INDICATION_ID);

        $this->assertInstanceOf(DeliveryIndication::class, $indication);
        $this->assertSame(self::INDICATION_ID, $indication->id);
        $this->assertSame(self::SPACE_ID, $indication->spaceId);
        $this->assertSame(State::PENDING, $indication->state);
        $this->assertSame(self::TRANSACTION_ID, $indication->transactionId);
        // Normalized to an ID on every API version, whatever the payload carries.
        $this->assertSame(self::COMPLETION_ID, $indication->completionId);
    }

    public function testGetPassesTheSpaceAndIndicationToTheSdkInTheOrderItExpects(): void
    {
        $this->expectSdkCall('read')->willReturn($this->makeSdkIndication());

        $this->gateway->get(self::SPACE_ID, self::INDICATION_ID);
    }

    public function testMarkAsNotSuitablePassesTheSpaceAndIndicationToTheSdkInTheOrderItExpects(): void
    {
        $this->expectSdkCall('markAsNotSuitable')
            ->willReturn($this->makeSdkIndication(SdkDeliveryIndicationState::NOT_SUITABLE));

        $this->gateway->markAsNotSuitable(self::SPACE_ID, self::INDICATION_ID);
    }

    public function testMarkAsNotSuitableReturnsTheDecidedIndication(): void
    {
        $this->service->method('markAsNotSuitable')
            ->willReturn($this->makeSdkIndication(SdkDeliveryIndicationState::NOT_SUITABLE));

        $indication = $this->gateway->markAsNotSuitable(self::SPACE_ID, self::INDICATION_ID);

        $this->assertSame(State::NOT_SUITABLE, $indication->state);
        $this->assertFalse($indication->isDecisionPending());
    }

    public function testMarkAsSuitablePassesTheSpaceAndIndicationToTheSdkInTheOrderItExpects(): void
    {
        $this->expectSdkCall('markAsSuitable')->willReturn($this->makeSdkIndication(SdkDeliveryIndicationState::SUITABLE));

        $this->gateway->markAsSuitable(self::SPACE_ID, self::INDICATION_ID);
    }

    public function testMarkAsSuitableReturnsTheDecidedIndication(): void
    {
        $this->service->method('markAsSuitable')
            ->willReturn($this->makeSdkIndication(SdkDeliveryIndicationState::SUITABLE));

        $indication = $this->gateway->markAsSuitable(self::SPACE_ID, self::INDICATION_ID);

        $this->assertSame(State::SUITABLE, $indication->state);
        $this->assertFalse($indication->isDecisionPending());
    }

    public function testTheWrappedFailureKeepsTheSdkExceptionAsPrevious(): void
    {
        $sdkException = new \Exception('SDK unavailable');
        $this->service->method('read')->willThrowException($sdkException);

        try {
            $this->gateway->get(self::SPACE_ID, self::INDICATION_ID);
            $this->fail('Expected a DeliveryIndicationException.');
        } catch (DeliveryIndicationException $e) {
            $this->assertSame($sdkException, $e->getPrevious());
            $this->assertStringContainsString((string)self::INDICATION_ID, $e->getMessage());
            $this->assertStringContainsString((string)self::SPACE_ID, $e->getMessage());
        }
    }
}
