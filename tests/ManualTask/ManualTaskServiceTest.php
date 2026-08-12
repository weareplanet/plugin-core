<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\ManualTask;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\ManualTask\Exception\ManualTaskException;
use WeArePlanet\PluginCore\ManualTask\ManualTaskGatewayInterface;
use WeArePlanet\PluginCore\ManualTask\ManualTaskService;
use WeArePlanet\PluginCore\ManualTask\State;

class ManualTaskServiceTest extends TestCase
{
    private const SPACE_ID = 42;

    private MockObject|ManualTaskGatewayInterface $gateway;
    private ManualTaskService $service;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(ManualTaskGatewayInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new ManualTaskService($this->gateway, $logger);
    }

    public function testCountByStateDelegatesToGatewayAndReturnsResult(): void
    {
        $this->gateway->expects($this->once())
            ->method('countByState')
            ->with(self::SPACE_ID, State::OPEN)
            ->willReturn(7);

        $this->assertSame(7, $this->service->countByState(self::SPACE_ID, State::OPEN));
    }

    public function testCountByStatePassesTheRequestedStateThrough(): void
    {
        $this->gateway->expects($this->once())
            ->method('countByState')
            ->with(self::SPACE_ID, State::DONE)
            ->willReturn(0);

        $this->assertSame(0, $this->service->countByState(self::SPACE_ID, State::DONE));
    }

    public function testCountByStatePropagatesGatewayException(): void
    {
        $this->gateway->method('countByState')
            ->willThrowException(new ManualTaskException('boom'));

        $this->expectException(ManualTaskException::class);

        $this->service->countByState(self::SPACE_ID, State::OPEN);
    }

    public function testCountAllDelegatesToGatewayAndReturnsResult(): void
    {
        $this->gateway->expects($this->once())
            ->method('countAll')
            ->with(self::SPACE_ID)
            ->willReturn(14);

        $this->assertSame(14, $this->service->countAll(self::SPACE_ID));
    }

    public function testCountAllPropagatesGatewayException(): void
    {
        $this->gateway->method('countAll')
            ->willThrowException(new ManualTaskException('boom'));

        $this->expectException(ManualTaskException::class);

        $this->service->countAll(self::SPACE_ID);
    }

}
