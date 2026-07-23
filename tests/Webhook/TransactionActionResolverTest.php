<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Transaction\State as TransactionState;
use WeArePlanet\PluginCore\Webhook\Enum\LifecycleAction;
use WeArePlanet\PluginCore\Webhook\TransactionActionResolver;

class TransactionActionResolverTest extends TestCase
{
    private TransactionActionResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TransactionActionResolver();
    }

    /**
     * @return array<string, array{0: TransactionState, 1: LifecycleAction}>
     */
    public static function stateProvider(): array
    {
        return [
            'AUTHORIZED authorizes the order' => [TransactionState::AUTHORIZED, LifecycleAction::AUTHORIZE],
            'COMPLETED fulfills the order' => [TransactionState::COMPLETED, LifecycleAction::FULFILL],
            'FULFILL fulfills the order' => [TransactionState::FULFILL, LifecycleAction::FULFILL],
            'FAILED cancels the order' => [TransactionState::FAILED, LifecycleAction::CANCEL_ORDER],
            'VOIDED cancels the order' => [TransactionState::VOIDED, LifecycleAction::CANCEL_ORDER],
            'DECLINE cancels the order' => [TransactionState::DECLINE, LifecycleAction::CANCEL_ORDER],
            'CREATE is ignored' => [TransactionState::CREATE, LifecycleAction::IGNORE],
            'PENDING is ignored' => [TransactionState::PENDING, LifecycleAction::IGNORE],
            'CONFIRMED is ignored' => [TransactionState::CONFIRMED, LifecycleAction::IGNORE],
            'PROCESSING is ignored' => [TransactionState::PROCESSING, LifecycleAction::IGNORE],
        ];
    }

    #[DataProvider('stateProvider')]
    public function testResolve(TransactionState $state, LifecycleAction $expected): void
    {
        $this->assertSame($expected, $this->resolver->resolve($state));
    }

    public function testEveryTransactionStateCaseIsCoveredByTheProvider(): void
    {
        $providedStates = array_map(
            static fn (array $case): TransactionState => $case[0],
            self::stateProvider(),
        );

        $this->assertCount(count(TransactionState::cases()), $providedStates);

        foreach (TransactionState::cases() as $case) {
            $this->assertContains($case, $providedStates, "State {$case->value} is missing from the test provider.");
        }
    }
}
