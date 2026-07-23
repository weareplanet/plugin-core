<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\State;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Refund\State as RefundState;
use WeArePlanet\PluginCore\Token\State as TokenState;
use WeArePlanet\PluginCore\Transaction\State as TransactionState;

/**
 * Tests isPending()/isTerminal(), provided by the ValidatesStateTransitions
 * trait, against a representative sample of the enums that use it.
 */
class ValidatesStateTransitionsTest extends TestCase
{
    /**
     * @return array<string, array{0: RefundState, 1: bool}>
     */
    public static function refundStateProvider(): array
    {
        return [
            'CREATE is pending' => [RefundState::CREATE, false],
            'SCHEDULED is pending' => [RefundState::SCHEDULED, false],
            'PENDING is pending' => [RefundState::PENDING, false],
            'MANUAL_CHECK is pending' => [RefundState::MANUAL_CHECK, false],
            'FAILED is terminal' => [RefundState::FAILED, true],
            'SUCCESSFUL is terminal' => [RefundState::SUCCESSFUL, true],
        ];
    }

    #[DataProvider('refundStateProvider')]
    public function testRefundStateIsTerminal(RefundState $state, bool $expectedTerminal): void
    {
        $this->assertSame($expectedTerminal, $state->isTerminal());
        $this->assertSame(!$expectedTerminal, $state->isPending());
    }

    /**
     * Token::State has no literal PENDING case (it's a CRUD-style lifecycle:
     * CREATE -> ACTIVE/INACTIVE -> DELETED), but isTerminal()/isPending() are
     * still meaningful: only DELETED is a final, resolved state.
     */
    public function testTokenStateOnlyDeletedIsTerminal(): void
    {
        $this->assertFalse(TokenState::CREATE->isTerminal());
        $this->assertFalse(TokenState::ACTIVE->isTerminal());
        $this->assertFalse(TokenState::INACTIVE->isTerminal());
        $this->assertFalse(TokenState::DELETING->isTerminal());
        $this->assertTrue(TokenState::DELETED->isTerminal());

        $this->assertTrue(TokenState::ACTIVE->isPending());
        $this->assertFalse(TokenState::DELETED->isPending());
    }

    #[DataProvider('transactionStateProvider')]
    public function testTransactionStateIsTerminal(TransactionState $state, bool $expectedTerminal): void
    {
        $this->assertSame($expectedTerminal, $state->isTerminal());
        $this->assertSame(!$expectedTerminal, $state->isPending());
    }

    /**
     * @return array<string, array{0: TransactionState, 1: bool}>
     */
    public static function transactionStateProvider(): array
    {
        return [
            'CREATE is pending' => [TransactionState::CREATE, false],
            'PENDING is pending' => [TransactionState::PENDING, false],
            'CONFIRMED is pending' => [TransactionState::CONFIRMED, false],
            'PROCESSING is pending' => [TransactionState::PROCESSING, false],
            'AUTHORIZED is pending' => [TransactionState::AUTHORIZED, false],
            'FAILED is terminal' => [TransactionState::FAILED, true],
            'VOIDED is terminal' => [TransactionState::VOIDED, true],
            'FULFILL is terminal' => [TransactionState::FULFILL, true],
            'DECLINE is terminal' => [TransactionState::DECLINE, true],
        ];
    }
}
