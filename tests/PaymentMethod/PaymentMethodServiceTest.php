<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\PaymentMethod;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodGatewayInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodRepositoryInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodService;
use WeArePlanet\PluginCore\PaymentMethod\State;

/**
 * Unit tests for PaymentMethodService.
 */
class PaymentMethodServiceTest extends TestCase
{
    /**
     * Factory helper to reduce boilerplate when constructing PaymentMethod value objects.
     */
    private function createPaymentMethod(int $id, int $spaceId, string $name): PaymentMethod
    {
        return new PaymentMethod(
            id: $id,
            spaceId: $spaceId,
            state: State::ACTIVE,
            title: new LocalizedString(['en-US' => $name]),
            description: new LocalizedString(null),
            sortOrder: 1,
            imageUrl: null,
        );
    }

    /**
     * Verifies that fetching available methods delegates to the gateway and returns the result.
     */
    public function testGetAvailableMethods(): void
    {
        $spaceId = 123;
        $mockMethods = [
            $this->createPaymentMethod(id: 1, spaceId: $spaceId, name: 'Credit Card'),
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

    /**
     * Verifies that a state filter is forwarded to the gateway.
     */
    public function testGetAvailableMethodsWithState(): void
    {
        $spaceId = 123;
        $state = 'active';
        $mockMethods = [
            $this->createPaymentMethod(id: 1, spaceId: $spaceId, name: 'Credit Card'),
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

    /**
     * Verifies that fetching a single method delegates to the gateway.
     */
    public function testGetPaymentMethod(): void
    {
        $spaceId = 123;
        $methodId = 1;
        $mockMethod = $this->createPaymentMethod(id: $methodId, spaceId: $spaceId, name: 'Credit Card');

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

    /**
     * When all API methods are new (no local IDs exist), only create() should be called.
     * update() and deactivateByExternalId() must never be invoked.
     */
    public function testSynchronizeAllNew(): void
    {
        $spaceId = 123;
        $methodA = $this->createPaymentMethod(id: 1, spaceId: $spaceId, name: 'Method A');
        $methodB = $this->createPaymentMethod(id: 2, spaceId: $spaceId, name: 'Method B');

        $gateway = $this->createMock(PaymentMethodGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('fetchBySpaceId')
            ->with($spaceId)
            ->willReturn([$methodA, $methodB]);

        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);

        // Local database has no existing methods.
        $repository->expects($this->once())
            ->method('getExistingExternalIds')
            ->with($spaceId)
            ->willReturn([]);

        // Both methods should be created.
        $repository->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function (PaymentMethod $method, int $space) use ($spaceId, $methodA, $methodB): void {
                $this->assertSame($spaceId, $space);
                $this->assertContains($method->id, [$methodA->id, $methodB->id]);
            });

        // No existing methods to update or orphans to deactivate.
        $repository->expects($this->never())->method('update');
        $repository->expects($this->never())->method('deactivateByExternalId');

        $logger = $this->createMock(LoggerInterface::class);

        $service = new PaymentMethodService($gateway, $repository, $logger);
        $service->synchronize($spaceId);
    }

    /**
     * Verifies that the gateway error propagates correctly.
     */
    public function testSynchronizeGatewayError(): void
    {
        $spaceId = 123;
        $expectedException = new \RuntimeException('API connection failed');

        $gateway = $this->createMock(PaymentMethodGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('fetchBySpaceId')
            ->with($spaceId)
            ->willThrowException($expectedException);

        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);

        $logger = $this->createMock(LoggerInterface::class);

        $service = new PaymentMethodService($gateway, $repository, $logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API connection failed');

        $service->synchronize($spaceId);
    }

    /**
     * Legacy Sync: Verify that update() IS called if the repository only returns a list of IDs.
     */
    public function testSynchronizeLegacyUpdate(): void
    {
        $spaceId = 123;
        $method = $this->createPaymentMethod(id: 1, spaceId: $spaceId, name: 'Method A');

        $gateway = $this->createMock(PaymentMethodGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('fetchBySpaceId')
            ->willReturn([$method]);

        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);

        // Legacy repository returns a simple list of IDs.
        $repository->expects($this->once())
            ->method('getExistingExternalIds')
            ->with($spaceId)
            ->willReturn([1]);

        // update() SHOULD be called (backward compatibility).
        $repository->expects($this->once())
            ->method('update')
            ->with($method, $spaceId);

        $logger = $this->createMock(LoggerInterface::class);

        $service = new PaymentMethodService($gateway, $repository, $logger);
        $service->synchronize($spaceId);
    }

    /**
     * Mixed scenario: API returns IDs [1, 2], local DB has [2, 3].
     */
    public function testSynchronizeMixed(): void
    {
        $spaceId = 123;
        $methodOne = $this->createPaymentMethod(id: 1, spaceId: $spaceId, name: 'New Method');
        $methodTwo = $this->createPaymentMethod(id: 2, spaceId: $spaceId, name: 'Existing Method');

        $gateway = $this->createMock(PaymentMethodGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('fetchBySpaceId')
            ->with($spaceId)
            ->willReturn([$methodOne, $methodTwo]);

        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);

        // Local database already has methods 2 and 3.
        $repository->expects($this->once())
            ->method('getExistingExternalIds')
            ->with($spaceId)
            ->willReturn([2, 3]);

        // Method 1 is new — should be created.
        $repository->expects($this->once())
            ->method('create')
            ->with($methodOne, $spaceId);

        // Method 2 already exists — should be updated.
        $repository->expects($this->once())
            ->method('update')
            ->with($methodTwo, $spaceId);

        // Method 3 is orphaned — present locally but absent from the API response.
        $repository->expects($this->once())
            ->method('deactivateByExternalId')
            ->with(3, $spaceId);

        $logger = $this->createMock(LoggerInterface::class);

        $service = new PaymentMethodService($gateway, $repository, $logger);
        $service->synchronize($spaceId);
    }

    /**
     * Smart Sync: Verify that update() is NOT called if the signature matches.
     */
    public function testSynchronizeSmartSkip(): void
    {
        $spaceId = 123;
        $method = $this->createPaymentMethod(id: 1, spaceId: $spaceId, name: 'Method A');

        $gateway = $this->createMock(PaymentMethodGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('fetchBySpaceId')
            ->willReturn([$method]);

        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);

        // Repository returns an associative array [id => signature] with matching signature.
        $repository->expects($this->once())
            ->method('getExistingExternalIds')
            ->willReturn([$method->id => $method->getSignature()]);

        // update() should NEVER be called because the data hasn't changed.
        $repository->expects($this->never())->method('update');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('1 skipped'));

        $service = new PaymentMethodService($gateway, $repository, $logger);
        $service->synchronize($spaceId);
    }

    /**
     * Smart Sync: Verify that update() IS called if the signature differs.
     */
    public function testSynchronizeSmartUpdate(): void
    {
        $spaceId = 123;
        $method = $this->createPaymentMethod(id: 1, spaceId: $spaceId, name: 'Method A');

        $gateway = $this->createMock(PaymentMethodGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('fetchBySpaceId')
            ->willReturn([$method]);

        $repository = $this->createMock(PaymentMethodRepositoryInterface::class);

        // Repository returns an associative array [id => signature] with a DIFFERENT signature.
        $repository->expects($this->once())
            ->method('getExistingExternalIds')
            ->willReturn([$method->id => 'old_signature']);

        // update() SHOULD be called.
        $repository->expects($this->once())
            ->method('update')
            ->with($method, $spaceId);

        $logger = $this->createMock(LoggerInterface::class);

        $service = new PaymentMethodService($gateway, $repository, $logger);
        $service->synchronize($spaceId);
    }
}
