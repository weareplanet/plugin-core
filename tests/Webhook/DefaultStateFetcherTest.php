<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Http\Request;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Transaction\State;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\TransactionGatewayInterface;
use WeArePlanet\PluginCore\Webhook\DefaultStateFetcher;
use WeArePlanet\Sdk\Service\WebhookEncryptionService;

class DefaultStateFetcherTest extends TestCase
{
    private WebhookEncryptionService $encryptionServiceMock;
    private DefaultStateFetcher $fetcher;
    private TransactionGatewayInterface $gatewayMock;
    private SdkProvider $sdkProviderMock;
    private Settings $settingsMock;

    /**
     * Helper method to create Request instances for tests using reflection.
     */
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     */
    private function createRequest(array $headers, array $body, string $rawBody): Request
    {
        $reflection = new \ReflectionClass(Request::class);
        $constructor = $reflection->getConstructor();
        $constructor->setAccessible(true);
        $request = $reflection->newInstanceWithoutConstructor();
        $constructor->invoke($request, $headers, $body, $rawBody);
        return $request;
    }

    protected function setUp(): void
    {
        $this->sdkProviderMock = $this->createMock(SdkProvider::class);
        $this->settingsMock = $this->createMock(Settings::class);
        $this->gatewayMock = $this->createMock(TransactionGatewayInterface::class);

        $this->encryptionServiceMock = $this->createMock(WebhookEncryptionService::class);

        $this->sdkProviderMock->method('getService')
            ->willReturnMap([
                [WebhookEncryptionService::class, $this->encryptionServiceMock],
            ]);

        $this->settingsMock->method('getSpaceId')->willReturn(1234);

        $this->fetcher = new DefaultStateFetcher(
            $this->sdkProviderMock,
            $this->settingsMock,
            $this->gatewayMock,
        );
    }

    public function testFetchStateCallsGatewayWhenSignatureIsMissing(): void
    {
        // --- Arrange ---
        $request = $this->createRequest([], [], ''); // No signature

        $mockTransaction = new Transaction();
        $mockTransaction->state = State::PENDING;

        // Configure the gateway mock
        $this->gatewayMock
            ->expects($this->once())
            ->method('get')
            ->with(1234, 567)
            ->willReturn($mockTransaction);

        // --- Act ---
        $state = $this->fetcher->fetchState($request, 567);

        // --- Assert ---
        $this->assertSame('PENDING', $state);
    }

    public function testFetchStateReturnsStateFromSignedPayloadWhenSignatureIsValid(): void
    {
        // --- Arrange ---
        $request = $this->createRequest(
            ['x-signature' => 'a-valid-signature-header'],
            ['state' => 'COMPLETED'],
            'raw-body-content',
        );

        $this->encryptionServiceMock
            ->expects($this->once())
            ->method('isContentValid')
            ->with('a-valid-signature-header', 'raw-body-content')
            ->willReturn(true);

        // --- Act ---
        $state = $this->fetcher->fetchState($request, 567);

        // --- Assert ---
        $this->assertSame('COMPLETED', $state);
    }

    public function testFetchStateThrowsExceptionWhenSignatureIsInvalid(): void
    {
        // --- Assert ---
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid webhook signature.');

        // --- Arrange ---
        $request = $this->createRequest(
            ['x-signature' => 'an-invalid-signature-header'],
            [],
            'raw-body-content',
        );

        $this->encryptionServiceMock->method('isContentValid')->willReturn(false);

        // --- Act ---
        $this->fetcher->fetchState($request, 567);
    }
}
