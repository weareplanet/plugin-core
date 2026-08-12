<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\DeliveryIndication;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\DeliveryIndication\DeliveryIndication;
use WeArePlanet\PluginCore\DeliveryIndication\DeliveryIndicationGatewayInterface;
use WeArePlanet\PluginCore\DeliveryIndication\DeliveryIndicationService;
use WeArePlanet\PluginCore\DeliveryIndication\Exception\DeliveryIndicationException;
use WeArePlanet\PluginCore\DeliveryIndication\State;
use WeArePlanet\PluginCore\Log\LoggerInterface;

class DeliveryIndicationServiceTest extends TestCase
{
    private const INDICATION_ID = 555;
    private const SPACE_ID = 42;
    private const TRANSACTION_ID = 1234;

    private MockObject|DeliveryIndicationGatewayInterface $gateway;
    private DeliveryIndicationService $service;

    private function makeIndication(State $state = State::PENDING): DeliveryIndication
    {
        return new DeliveryIndication(
            id: self::INDICATION_ID,
            spaceId: self::SPACE_ID,
            state: $state,
            transactionId: self::TRANSACTION_ID,
        );
    }

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(DeliveryIndicationGatewayInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new DeliveryIndicationService($this->gateway, $logger);
    }

    public function testFindByTransactionDelegatesToGateway(): void
    {
        $indication = $this->makeIndication();

        $this->gateway->expects($this->once())
            ->method('findByTransaction')
            ->with(self::SPACE_ID, self::TRANSACTION_ID)
            ->willReturn($indication);

        $this->assertSame(
            $indication,
            $this->service->findByTransaction(self::SPACE_ID, self::TRANSACTION_ID),
        );
    }

    public function testFindByTransactionReturnsNullWhenThereIsNoIndication(): void
    {
        $this->gateway->expects($this->once())
            ->method('findByTransaction')
            ->with(self::SPACE_ID, self::TRANSACTION_ID)
            ->willReturn(null);

        $this->assertNull($this->service->findByTransaction(self::SPACE_ID, self::TRANSACTION_ID));
    }

    public function testGetDelegatesToGateway(): void
    {
        $indication = $this->makeIndication();

        $this->gateway->expects($this->once())
            ->method('get')
            ->with(self::SPACE_ID, self::INDICATION_ID)
            ->willReturn($indication);

        $this->assertSame($indication, $this->service->get(self::SPACE_ID, self::INDICATION_ID));
    }

    public function testMarkAsNotSuitableDelegatesAndReturnsUpdatedIndication(): void
    {
        $decided = $this->makeIndication(State::NOT_SUITABLE);

        $this->gateway->expects($this->once())
            ->method('markAsNotSuitable')
            ->with(self::SPACE_ID, self::INDICATION_ID)
            ->willReturn($decided);

        $this->assertSame($decided, $this->service->markAsNotSuitable(self::SPACE_ID, self::INDICATION_ID));
    }

    public function testMarkAsSuitableDelegatesAndReturnsUpdatedIndication(): void
    {
        $decided = $this->makeIndication(State::SUITABLE);

        $this->gateway->expects($this->once())
            ->method('markAsSuitable')
            ->with(self::SPACE_ID, self::INDICATION_ID)
            ->willReturn($decided);

        $this->assertSame($decided, $this->service->markAsSuitable(self::SPACE_ID, self::INDICATION_ID));
    }

    public function testPropagatesGatewayException(): void
    {
        $this->gateway->method('get')
            ->willThrowException(new DeliveryIndicationException('boom'));

        $this->expectException(DeliveryIndicationException::class);

        $this->service->get(self::SPACE_ID, self::INDICATION_ID);
    }
}
