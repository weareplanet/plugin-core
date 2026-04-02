<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\PaymentMethod;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodGatewayInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodRepositoryInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodService;

/**
 * Unit tests for PaymentMethodService.
 */
class PaymentMethodServiceTest extends TestCase
{
    public function testGetAvailableMethods(): void
    {
        $spaceId = 123;
        $mockMethods = [
            new PaymentMethod(
                id: 1,
                spaceId: $spaceId,
                state: 'active',
                name: 'Credit Card',
                title: ['en-US' => 'Credit Card'],
                description: 'Pay with Credit Card',
                descriptionMap: ['en-US' => 'Pay with Credit Card'],
                sortOrder: 1,
                imageUrl: 'https://example.com/image.png',
            ),
        ];

        $gateway = $this->createMock(PaymentMethodGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('fetchBySpaceId')
            ->with($spaceId, null)
            ->willReturn($mockMethods);

        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info');

        $service = new PaymentMethodService($gateway, $repository, $logger);
        $result = $service->getPaymentMethods($spaceId);

        $this->assertSame($mockMethods, $result);
    }

    public function testGetAvailableMethodsWithState(): void
    {
        $spaceId = 123;
        $state = 'active';
        $mockMethods = [
            new PaymentMethod(
                id: 1,
                spaceId: $spaceId,
                state: 'active',
                name: 'Credit Card',
                title: ['en-US' => 'Credit Card'],
                description: 'Pay with Credit Card',
                descriptionMap: ['en-US' => 'Pay with Credit Card'],
                sortOrder: 1,
                imageUrl: 'https://example.com/image.png',
            ),
        ];

        $gateway = $this->createMock(PaymentMethodGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('fetchBySpaceId')
            ->with($spaceId, $state)
            ->willReturn($mockMethods);

        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $service = new PaymentMethodService($gateway, $repository, $logger);
        $result = $service->getPaymentMethods($spaceId, $state);

        $this->assertSame($mockMethods, $result);
    }

    public function testSynchronize(): void
    {
        $spaceId = 123;
        $mockMethods = [
            new PaymentMethod(
                id: 1,
                spaceId: $spaceId,
                state: 'active',
                name: 'Method 1',
                title: [],
                description: null,
                descriptionMap: [],
                sortOrder: 1,
                imageUrl: null,
            ),
        ];

        $gateway = $this->createMock(PaymentMethodGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('fetchBySpaceId')
            ->with($spaceId)
            ->willReturn($mockMethods);

        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('sync')
            ->with($spaceId, $mockMethods);

        $logger = $this->createMock(LoggerInterface::class);

        $service = new PaymentMethodService($gateway, $repository, $logger);
        $service->synchronize($spaceId);
    }

    public function testGetPaymentMethod(): void
    {
        $spaceId = 123;
        $methodId = 1;
        $mockMethod = new PaymentMethod(
            id: $methodId,
            spaceId: $spaceId,
            state: 'active',
            name: 'Credit Card',
            title: ['en-US' => 'Credit Card'],
            description: 'Pay with Credit Card',
            descriptionMap: ['en-US' => 'Pay with Credit Card'],
            sortOrder: 1,
            imageUrl: 'https://example.com/image.png',
        );

        $gateway = $this->createMock(PaymentMethodGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('fetchById')
            ->with($spaceId, $methodId)
            ->willReturn($mockMethod);

        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $service = new PaymentMethodService($gateway, $repository, $logger);
        $result = $service->getPaymentMethod($spaceId, $methodId);

        $this->assertSame($mockMethod, $result);
    }
}
