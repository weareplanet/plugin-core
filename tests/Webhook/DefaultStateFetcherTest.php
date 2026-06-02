<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Http\Request;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Webhook\DefaultStateFetcher;
use WeArePlanet\Sdk\Service\DeliveryIndicationService;
use WeArePlanet\Sdk\Service\ManualTaskService;
use WeArePlanet\Sdk\Service\RefundService;
use WeArePlanet\Sdk\Service\TokenService;
use WeArePlanet\Sdk\Service\TransactionCompletionService;
use WeArePlanet\Sdk\Service\TransactionInvoiceService;
use WeArePlanet\Sdk\Service\TransactionService;
use WeArePlanet\Sdk\Service\TransactionVoidService;
use WeArePlanet\Sdk\Service\WebhookEncryptionService;

/**
 * Unit tests for the DefaultStateFetcher class.
 */
class DefaultStateFetcherTest extends TestCase
{
    private WebhookEncryptionService $encryptionServiceMock;

    private DefaultStateFetcher $fetcher;

    private SdkProvider $sdkProviderMock;

    /**
     * Registry of service class name to its mock instance.
     *
     * @var array<string, object>
     */
    private array $services = [];

    private Settings $settingsMock;

    /**
     * Helper method to create Request instances for tests using reflection.
     *
     * @param array<string, string> $headers The request headers.
     * @param array<string, mixed> $body The request body data.
     * @param string $rawBody The raw request body.
     * @return Request The created Request instance.
     */
    private function createRequest(array $headers, array $body, string $rawBody): Request
    {
        $reflection = new \ReflectionClass(Request::class, );
        $constructor = $reflection->getConstructor();
        $constructor->setAccessible(true, );
        $request = $reflection->newInstanceWithoutConstructor();
        $constructor->invoke($request, $headers, $body, $rawBody, );
        return $request;
    }

    /**
     * Set up the test fixtures.
     */
    protected function setUp(): void
    {
        $this->sdkProviderMock = $this->createMock(SdkProvider::class, );
        $this->settingsMock = $this->createMock(Settings::class, );
        $this->encryptionServiceMock = $this->createMock(WebhookEncryptionService::class, );

        $this->services = [
            WebhookEncryptionService::class => $this->encryptionServiceMock,
        ];

        // Route all SDK provider service fetches through the internal services registry.
        $this->sdkProviderMock->method('getService', )
            ->willReturnCallback(function (string $serviceClass): object {
                if (isset($this->services[$serviceClass])) {
                    return $this->services[$serviceClass];
                }
                throw new \InvalidArgumentException("Service not mocked: " . $serviceClass, );
            }, );

        $this->settingsMock->method('getSpaceId', )->willReturn(1234, );

        $this->fetcher = new DefaultStateFetcher(
            $this->sdkProviderMock,
            $this->settingsMock,
        );
    }

    /**
     * Data provider for supported entity types and their corresponding SDK service classes.
     *
     * @return array<string, array{0: string, 1: string, 2: string}> The dataset.
     */
    public static function supportedEntitiesProvider(): array
    {
        return [
            'DeliveryIndication' => [
                'DeliveryIndication',
                DeliveryIndicationService::class,
                'PENDING',
            ],
            'ManualTask' => [
                'ManualTask',
                ManualTaskService::class,
                'DONE',
            ],
            'Refund' => [
                'Refund',
                RefundService::class,
                'SUCCESSFUL',
            ],
            'Token' => [
                'Token',
                TokenService::class,
                'ACTIVE',
            ],
            'Transaction' => [
                'Transaction',
                TransactionService::class,
                'CONFIRMED',
            ],
            'TransactionCompletion' => [
                'TransactionCompletion',
                TransactionCompletionService::class,
                'SUCCESSFUL',
            ],
            'TransactionInvoice' => [
                'TransactionInvoice',
                TransactionInvoiceService::class,
                'PAID',
            ],
            'TransactionVoid' => [
                'TransactionVoid',
                TransactionVoidService::class,
                'SUCCESSFUL',
            ],
        ];
    }

    /**
     * Verifies that the correct service is invoked to fetch state for all supported entities.
     *
     * @dataProvider supportedEntitiesProvider
     *
     * @param string $technicalName The technical name of the entity.
     * @param class-string $serviceClass The SDK service class name.
     * @param string $expectedState The expected return state value.
     */
    public function testFetchStateCallsCorrectServiceForSupportedEntities(
        string $technicalName,
        string $serviceClass,
        string $expectedState,
    ): void {
        // --- Arrange ---
        $request = $this->createRequest(
            [],
            ['listenerEntityTechnicalName' => $technicalName,],
            '',
        );

        $mockEntity = $this->createMock(DefaultStateFetcherTestMockEntity::class, );
        $mockEntity->expects($this->once(), )
            ->method('getState', )
            ->willReturn($expectedState, );

        $serviceMock = $this->createMock($serviceClass, );
        $serviceMock->expects($this->once(), )
            ->method('read', )
            ->with(1234, 567, )
            ->willReturn($mockEntity, );

        $this->services[$serviceClass] = $serviceMock;

        // --- Act ---
        $state = $this->fetcher->fetchState($request, 567, );

        // --- Assert ---
        $this->assertSame($expectedState, $state, );
    }

    /**
     * Verifies that the signed state is returned when a valid signature exists.
     */
    public function testFetchStateReturnsStateFromSignedPayloadWhenSignatureIsValid(): void
    {
        // --- Arrange ---
        $request = $this->createRequest(
            ['x-signature' => 'a-valid-signature-header',],
            ['state' => 'COMPLETED',],
            'raw-body-content',
        );

        $this->encryptionServiceMock
            ->expects($this->once(), )
            ->method('isContentValid', )
            ->with('a-valid-signature-header', 'raw-body-content', )
            ->willReturn(true, );

        // --- Act ---
        $state = $this->fetcher->fetchState($request, 567, );

        // --- Assert ---
        $this->assertSame('COMPLETED', $state, );
    }

    /**
     * Verifies that an exception is thrown when signature validation fails.
     */
    public function testFetchStateThrowsExceptionWhenSignatureIsInvalid(): void
    {
        // --- Assert ---
        $this->expectException(\Exception::class, );
        $this->expectExceptionMessage('Invalid webhook signature.', );

        // --- Arrange ---
        $request = $this->createRequest(
            ['x-signature' => 'an-invalid-signature-header',],
            [],
            'raw-body-content',
        );

        $this->encryptionServiceMock->method('isContentValid', )->willReturn(false, );

        // --- Act ---
        $this->fetcher->fetchState($request, 567, );
    }

    /**
     * Verifies that an exception is thrown when the technical name is unsupported.
     */
    public function testFetchStateThrowsExceptionWhenTechnicalNameIsUnsupported(): void
    {
        // --- Assert ---
        $this->expectException(\Exception::class, );
        $this->expectExceptionMessage('Legacy state fetching not supported for entity: UnsupportedEntity', );

        // --- Arrange ---
        $request = $this->createRequest(
            [],
            ['listenerEntityTechnicalName' => 'UnsupportedEntity',],
            '',
        );

        // --- Act ---
        $this->fetcher->fetchState($request, 567, );
    }
}

/**
 * Helper interface to represent an SDK entity for mocking state retrieval.
 */
interface DefaultStateFetcherTestMockEntity
{
    /**
     * Retrieves the state of the mocked entity.
     *
     * @return string The state of the entity.
     */
    public function getState(): string;
}
