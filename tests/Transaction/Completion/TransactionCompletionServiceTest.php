<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction\Completion;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Transaction\Completion\CaptureRequest;
use WeArePlanet\PluginCore\Transaction\Completion\State;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletion;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletionGatewayInterface;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletionService;
use WeArePlanet\PluginCore\Transaction\State as TransactionState;
use WeArePlanet\PluginCore\Transaction\Transaction;

class TransactionCompletionServiceTest extends TestCase
{
    private const COMPLETION_ID = 999;
    private const SPACE_ID = 42;
    private const TRANSACTION_ID = 1234;

    private MockObject|TransactionCompletionGatewayInterface $gateway;
    private TransactionCompletionService $service;

    /**
     * A capture line item carries only what the capture endpoint transmits:
     * uniqueId, quantity and amount. Name, sku and taxes are deliberately unset.
     */
    private function makeCaptureRequest(): CaptureRequest
    {
        $item = new LineItem();
        $item->uniqueId = 'sku-123';
        $item->quantity = 1.0;
        $item->amountIncludingTax = 25.00;

        return new CaptureRequest(
            lineItems: new LineItemCollection($item),
            isFinal: false,
            externalId: 'capture-1234-shipment-1',
        );
    }

    /**
     * The service logs $id and $state on success, so a completion fixture must
     * have both set — the gateway's mapper always populates them.
     */
    private function makeCompletion(): TransactionCompletion
    {
        $completion = new TransactionCompletion();
        $completion->id = self::COMPLETION_ID;
        $completion->linkedTransactionId = self::TRANSACTION_ID;
        $completion->state = State::SUCCESSFUL;

        return $completion;
    }

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(TransactionCompletionGatewayInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new TransactionCompletionService($this->gateway, $logger);
    }

    public function testCanCompleteIsFalseForEveryNonAuthorizedState(): void
    {
        foreach (TransactionState::cases() as $state) {
            if ($state === TransactionState::AUTHORIZED) {
                continue;
            }

            $transaction = new Transaction();
            $transaction->state = $state;

            $this->assertFalse(
                $this->service->canComplete($transaction),
                "Expected {$state->value} to disallow completion.",
            );
        }
    }

    public function testCanCompleteIsFalseOnceAlreadyCaptured(): void
    {
        // A capture moves the transaction out of AUTHORIZED, which is why a second
        // capture against the same transaction is rejected by the API.
        $transaction = new Transaction();
        $transaction->state = TransactionState::COMPLETED;

        $this->assertFalse($this->service->canComplete($transaction));
    }

    public function testCanCompleteIsTrueForAnAuthorizedTransaction(): void
    {
        $transaction = new Transaction();
        $transaction->state = TransactionState::AUTHORIZED;

        $this->assertTrue($this->service->canComplete($transaction));
    }

    public function testCaptureForwardsCaptureRequestToGateway(): void
    {
        $request = $this->makeCaptureRequest();
        $completion = $this->makeCompletion();

        $this->gateway->expects($this->once())
            ->method('capture')
            ->with(self::SPACE_ID, self::TRANSACTION_ID, $this->identicalTo($request))
            ->willReturn($completion);

        $this->assertSame(
            $completion,
            $this->service->capture(self::SPACE_ID, self::TRANSACTION_ID, $request),
        );
    }

    public function testCaptureWithoutRequestPassesNullForAFullCapture(): void
    {
        $completion = $this->makeCompletion();

        $this->gateway->expects($this->once())
            ->method('capture')
            ->with(self::SPACE_ID, self::TRANSACTION_ID, null)
            ->willReturn($completion);

        $this->assertSame($completion, $this->service->capture(self::SPACE_ID, self::TRANSACTION_ID));
    }

    public function testFindDelegatesToGateway(): void
    {
        $completion = $this->makeCompletion();

        $this->gateway->expects($this->once())
            ->method('find')
            ->with(self::SPACE_ID, self::COMPLETION_ID)
            ->willReturn($completion);

        $this->assertSame($completion, $this->service->find(self::SPACE_ID, self::COMPLETION_ID));
    }

    public function testFindReturnsNullWhenTheCompletionDoesNotExist(): void
    {
        $this->gateway->expects($this->once())
            ->method('find')
            ->with(self::SPACE_ID, self::COMPLETION_ID)
            ->willReturn(null);

        $this->assertNull($this->service->find(self::SPACE_ID, self::COMPLETION_ID));
    }

    public function testGetDelegatesToGateway(): void
    {
        $completion = $this->makeCompletion();

        $this->gateway->expects($this->once())
            ->method('get')
            ->with(self::SPACE_ID, self::COMPLETION_ID)
            ->willReturn($completion);

        $this->assertSame($completion, $this->service->get(self::SPACE_ID, self::COMPLETION_ID));
    }

}
