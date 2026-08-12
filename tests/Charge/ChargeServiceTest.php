<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Charge;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Charge\Attempt\ChargeAttempt;
use WeArePlanet\PluginCore\Charge\Attempt\Label;
use WeArePlanet\PluginCore\Charge\ChargeGatewayInterface;
use WeArePlanet\PluginCore\Charge\ChargeService;
use WeArePlanet\PluginCore\Charge\Exception\ChargeException;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Transaction\State;
use WeArePlanet\PluginCore\Transaction\Transaction;

class ChargeServiceTest extends TestCase
{
    private const SPACE_ID = 42;
    private const TRANSACTION_ID = 1234;

    private MockObject|ChargeGatewayInterface $gateway;
    private MockObject|LoggerInterface $logger;
    private ChargeService $service;

    private function makeTransaction(): Transaction
    {
        $transaction = new Transaction();
        $transaction->id = self::TRANSACTION_ID;
        $transaction->spaceId = self::SPACE_ID;
        $transaction->state = State::PROCESSING;

        return $transaction;
    }

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(ChargeGatewayInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ChargeService($this->gateway, $this->logger);
    }

    public function testApplyFlowDelegatesToTheGatewayAndReturnsItsTransaction(): void
    {
        $transaction = $this->makeTransaction();

        $this->gateway->expects($this->once())
            ->method('applyFlow')
            ->with(self::SPACE_ID, self::TRANSACTION_ID)
            ->willReturn($transaction);

        $this->assertSame($transaction, $this->service->applyFlow(self::SPACE_ID, self::TRANSACTION_ID));
    }

    public function testApplyFlowLetsTheGatewaysChargeExceptionThrough(): void
    {
        // The gateway already logs and wraps; the service adds neither, so the domain
        // exception must arrive at the caller unchanged rather than re-wrapped.
        $failure = new ChargeException(
            'Charge operation applyFlow failed.',
            new LocalizedString('An error occurred while processing the charge.'),
        );

        $this->gateway->method('applyFlow')->willThrowException($failure);

        try {
            $this->service->applyFlow(self::SPACE_ID, self::TRANSACTION_ID);
            $this->fail('Expected a ChargeException.');
        } catch (ChargeException $e) {
            $this->assertSame($failure, $e);
        }
    }

    public function testFindAllAttemptsByTransactionReturnsTheGatewaysFullList(): void
    {
        $attempts = [
            new ChargeAttempt(775, 'FAILED'),
            new ChargeAttempt(776, 'PROCESSING'),
            new ChargeAttempt(777, 'SUCCESSFUL', [new Label(1001, 'VISA', '4')]),
        ];

        $this->gateway->expects($this->once())
            ->method('findAllAttemptsByTransaction')
            ->with(self::SPACE_ID, self::TRANSACTION_ID)
            ->willReturn($attempts);

        // Every attempt is reported, in the order the gateway returned them — no
        // filtering happens on this path.
        $this->assertSame(
            $attempts,
            $this->service->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID),
        );
    }

    public function testFindAllAttemptsByTransactionReturnsAnEmptyListWhenThereAreNone(): void
    {
        $this->gateway->method('findAllAttemptsByTransaction')->willReturn([]);

        $this->assertSame(
            [],
            $this->service->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID),
        );
    }

    public function testFindAllAttemptsByTransactionLetsTheGatewaysChargeExceptionThrough(): void
    {
        $failure = new ChargeException(
            'Charge operation search failed.',
            new LocalizedString('An error occurred while processing the charge.'),
        );

        $this->gateway->method('findAllAttemptsByTransaction')->willThrowException($failure);

        $this->expectException(ChargeException::class);
        $this->service->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID);
    }

    public function testFindSuccessfulAttemptByTransactionPicksTheSuccessfulOneOutOfMixedAttempts(): void
    {
        $successful = new ChargeAttempt(777, 'SUCCESSFUL', [new Label(1001, 'VISA', '4')]);

        // A failed attempt comes first, so returning the head of the list would pass a
        // weaker test than this one: the successful attempt must be selected by state.
        $this->gateway->expects($this->once())
            ->method('findAllAttemptsByTransaction')
            ->with(self::SPACE_ID, self::TRANSACTION_ID)
            ->willReturn([
                new ChargeAttempt(775, 'FAILED'),
                new ChargeAttempt(776, 'PROCESSING'),
                $successful,
                new ChargeAttempt(778, 'FAILED'),
            ]);

        $this->assertSame(
            $successful,
            $this->service->findSuccessfulAttemptByTransaction(self::SPACE_ID, self::TRANSACTION_ID),
        );
    }

    public function testFindSuccessfulAttemptByTransactionReturnsNullWhenEveryAttemptFailed(): void
    {
        $this->gateway->method('findAllAttemptsByTransaction')->willReturn([
            new ChargeAttempt(775, 'FAILED'),
            new ChargeAttempt(776, 'PROCESSING'),
        ]);

        // Charged, but never successfully — an ordinary outcome, not an exception.
        $this->assertNull(
            $this->service->findSuccessfulAttemptByTransaction(self::SPACE_ID, self::TRANSACTION_ID),
        );
    }

    public function testFindSuccessfulAttemptByTransactionReturnsNullWhenThereAreNoAttempts(): void
    {
        // A transaction that was never charged has no attempts at all.
        $this->gateway->method('findAllAttemptsByTransaction')->willReturn([]);

        $this->assertNull(
            $this->service->findSuccessfulAttemptByTransaction(self::SPACE_ID, self::TRANSACTION_ID),
        );
    }

    public function testFindSuccessfulAttemptByTransactionLetsTheGatewaysChargeExceptionThrough(): void
    {
        $failure = new ChargeException(
            'Charge operation search failed.',
            new LocalizedString('An error occurred while processing the charge.'),
        );

        $this->gateway->method('findAllAttemptsByTransaction')->willThrowException($failure);

        $this->expectException(ChargeException::class);
        $this->service->findSuccessfulAttemptByTransaction(self::SPACE_ID, self::TRANSACTION_ID);
    }
}
