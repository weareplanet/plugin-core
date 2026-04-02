<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Http\Request;
use WeArePlanet\PluginCore\Webhook\DefaultStateFetcher;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Transaction\TransactionGatewayInterface;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\State as StateEnum;
use WeArePlanet\Sdk\Service\WebhookEncryptionKeysService as SdkWebhookEncryptionKeysService;

class DefaultStateFetcherTest extends TestCase
{
    private SdkProvider $sdkProviderMock;
    private Settings $settingsMock;
    private TransactionGatewayInterface $gatewayMock;
    private DefaultStateFetcher $fetcher;
    private SdkWebhookEncryptionKeysService $encryptionServiceMock;

    protected function setUp(): void
    {
        $this->sdkProviderMock = $this->createMock(SdkProvider::class);
        $this->settingsMock = $this->createMock(Settings::class);
        $this->gatewayMock = $this->createMock(TransactionGatewayInterface::class);

        $this->encryptionServiceMock = $this->createMock(SdkWebhookEncryptionKeysService::class);

        $this->sdkProviderMock->method('getService')
            ->willReturnMap([
                [SdkWebhookEncryptionKeysService::class, $this->encryptionServiceMock],
            ]);

        $this->settingsMock->method('getSpaceId')->willReturn(1234);

        $this->fetcher = new DefaultStateFetcher(
            $this->sdkProviderMock,
            $this->settingsMock,
            $this->gatewayMock,
        );
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

    public function testFetchStateCallsGatewayWhenSignatureIsMissing(): void
    {
        // --- Arrange ---
        $request = $this->createRequest([], [], ''); // No signature

        $mockTransaction = new Transaction();
        $mockTransaction->state = StateEnum::PENDING;

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

    /**
     * Helper method to create Request instances for tests using reflection.
     *
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
}
