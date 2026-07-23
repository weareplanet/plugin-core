<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TransactionCompletionGateway;
use WeArePlanet\PluginCore\Transaction\Completion\State;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletion;
use WeArePlanet\PluginCore\Transaction\Void\State as VoidState;
use WeArePlanet\PluginCore\Transaction\Void\TransactionVoid;
use WeArePlanet\Sdk\Model\FailureReason as SdkFailureReason;
use WeArePlanet\Sdk\Model\TransactionCompletion as SdkTransactionCompletion;
use WeArePlanet\Sdk\Model\TransactionCompletionState as SdkTransactionCompletionState;
use WeArePlanet\Sdk\Model\TransactionVoid as SdkTransactionVoid;
use WeArePlanet\Sdk\Model\TransactionVoidState as SdkTransactionVoidState;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;

/**
 * Tests the SDK v2 implementation of the transaction completion gateway.
 *
 * Verifies that SDK response structures (completions, voids, and their failure reasons)
 * are properly mapped to domain objects.
 */
class TransactionCompletionGatewayTest extends TestCase
{
    private TransactionCompletionGateway $gateway;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkTransactionsService $transactionsService;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->transactionsService = $this->createMock(SdkTransactionsService::class);

        $this->sdkProvider->method('getService')
            ->with(SdkTransactionsService::class)
            ->willReturn($this->transactionsService);

        $this->gateway = new TransactionCompletionGateway($this->sdkProvider, $this->createMock(LoggerInterface::class));
    }

    /**
     * Verifies that capture maps an SDK TransactionCompletion to the domain entity when successful.
     */
    public function testCaptureReturnsCompletion(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $sdkCompletion = new SdkTransactionCompletion();
        $sdkCompletion->setId(10);
        $sdkCompletion->setLinkedTransaction($transactionId);
        $sdkCompletion->setState(SdkTransactionCompletionState::SUCCESSFUL);

        $this->transactionsService->expects($this->once())
            ->method('postPaymentTransactionsIdCompleteOnline')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkCompletion);

        $result = $this->gateway->capture($spaceId, $transactionId);

        $this->assertInstanceOf(TransactionCompletion::class, $result);
        $this->assertEquals(10, $result->id);
        $this->assertEquals($transactionId, $result->linkedTransactionId);
        $this->assertEquals(State::SUCCESSFUL, $result->state);
        $this->assertNull($result->failureReason);
    }

    /**
     * Verifies that a failure reason is properly extracted from the SDK completion response
     * and mapped to a localized string on the domain entity.
     */
    public function testCaptureMapsFailureReason(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $failureReason = new SdkFailureReason();
        $failureReason->setDescription(['en-US' => 'Insufficient funds']);

        $sdkCompletion = new SdkTransactionCompletion();
        $sdkCompletion->setId(10);
        $sdkCompletion->setLinkedTransaction($transactionId);
        $sdkCompletion->setState(SdkTransactionCompletionState::FAILED);
        $sdkCompletion->setFailureReason($failureReason);

        $this->transactionsService->expects($this->once())
            ->method('postPaymentTransactionsIdCompleteOnline')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkCompletion);

        $result = $this->gateway->capture($spaceId, $transactionId);

        $this->assertNotNull($result->failureReason);
        $this->assertEquals('Insufficient funds', $result->failureReason->localize('en-US'));
    }

    /**
     * Verifies that void operation maps the SDK TransactionVoid response to a TransactionVoid domain entity on success.
     */
    public function testVoidReturnsTransactionVoid(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $sdkVoid = new SdkTransactionVoid();
        $sdkVoid->setState(SdkTransactionVoidState::SUCCESSFUL);

        $this->transactionsService->expects($this->once())
            ->method('postPaymentTransactionsIdVoidOnline')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkVoid);

        $result = $this->gateway->void($spaceId, $transactionId);

        $this->assertInstanceOf(TransactionVoid::class, $result);
        $this->assertEquals(VoidState::SUCCESSFUL, $result->state);
        $this->assertNull($result->failureReason);
    }

    /**
     * Verifies that a void failure reason from the SDK is correctly mapped to the domain
     * void object as a localized string.
     */
    public function testVoidMapsFailureReason(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $failureReason = new SdkFailureReason();
        $failureReason->setDescription(['en-US' => 'Void rejected by gateway']);

        $sdkVoid = new SdkTransactionVoid();
        $sdkVoid->setState(SdkTransactionVoidState::FAILED);
        $sdkVoid->setFailureReason($failureReason);

        $this->transactionsService->expects($this->once())
            ->method('postPaymentTransactionsIdVoidOnline')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkVoid);

        $result = $this->gateway->void($spaceId, $transactionId);

        $this->assertInstanceOf(TransactionVoid::class, $result);
        $this->assertEquals(VoidState::FAILED, $result->state);
        $this->assertNotNull($result->failureReason);
        $this->assertEquals('Void rejected by gateway', $result->failureReason->localize('en-US'));
    }
}
