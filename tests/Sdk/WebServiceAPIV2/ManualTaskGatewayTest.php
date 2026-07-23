<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\ManualTask\Exception\ManualTaskException;
use WeArePlanet\PluginCore\ManualTask\State;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\ManualTaskGateway;
use WeArePlanet\Sdk\Model\ManualTask as SdkManualTask;
use WeArePlanet\Sdk\Model\ManualTaskSearchResponse as SdkManualTaskSearchResponse;
use WeArePlanet\Sdk\Service\ManualTasksService as SdkManualTasksService;

class ManualTaskGatewayTest extends TestCase
{
    private ManualTaskGateway $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkManualTasksService $manualTasksService;
    private MockObject|SdkProvider $sdkProvider;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->manualTasksService = $this->createMock(SdkManualTasksService::class);

        $this->sdkProvider->method('getService')
            ->with(SdkManualTasksService::class)
            ->willReturn($this->manualTasksService);

        $this->gateway = new ManualTaskGateway(
            $this->sdkProvider,
            $this->logger,
        );
    }

    private function makePage(int $itemCount, bool $hasMore): SdkManualTaskSearchResponse
    {
        $page = new SdkManualTaskSearchResponse();
        $page->setData(array_map(static fn () => new SdkManualTask(), array_fill(0, $itemCount, null)));
        $page->setHasMore($hasMore);

        return $page;
    }

    public function testCountByStateReturnsCountFromASinglePage(): void
    {
        $spaceId = 42;

        $this->manualTasksService->expects($this->once())
            ->method('getManualTasksSearch')
            ->with($spaceId, null, 100, 0, null, 'state:OPEN')
            ->willReturn($this->makePage(7, false));

        $this->assertSame(7, $this->gateway->countByState($spaceId, State::OPEN));
    }

    public function testCountByStatePagesThroughResultsAndSumsThem(): void
    {
        $spaceId = 42;

        $this->manualTasksService->expects($this->exactly(2))
            ->method('getManualTasksSearch')
            ->willReturnOnConsecutiveCalls(
                $this->makePage(100, true),
                $this->makePage(23, false),
            );

        $this->assertSame(123, $this->gateway->countByState($spaceId, State::OPEN));
    }

    public function testCountByStateWrapsSdkFailuresInManualTaskException(): void
    {
        $spaceId = 42;

        $this->manualTasksService->expects($this->once())
            ->method('getManualTasksSearch')
            ->willThrowException(new \Exception('SDK unavailable'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to count manual tasks from SDK.'));

        $this->expectException(ManualTaskException::class);
        $this->gateway->countByState($spaceId, State::OPEN);
    }
}
