<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction\Completion;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Transaction\Completion\State;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletion;
use WeArePlanet\PluginCore\Transaction\Void\State as VoidState;
use WeArePlanet\PluginCore\Transaction\Void\TransactionVoid;

class TransactionCompletionTest extends TestCase
{
    public function testToString(): void
    {
        $completion = new TransactionCompletion();
        $completion->id = 70;
        $completion->linkedTransactionId = 1001;
        $completion->state = State::SUCCESSFUL;

        $json = (string) $completion;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertEquals(70, $decoded['id']);
        $this->assertEquals(1001, $decoded['linkedTransactionId']);
        $this->assertArrayHasKey('state', $decoded);
    }

    public function testFailureReasonDefaultsToNull(): void
    {
        $completion = new TransactionCompletion();

        $this->assertNull($completion->failureReason);
    }

    public function testFailureReasonIsLocalizable(): void
    {
        $completion = new TransactionCompletion();
        $completion->failureReason = new LocalizedString([
            'en-US' => 'Insufficient funds',
            'de-DE' => 'Unzureichende Deckung',
        ]);

        $this->assertSame('Unzureichende Deckung', $completion->failureReason->localize('de-DE'));
    }

    public function testVoidCarriesStateAndFailureReason(): void
    {
        $void = new TransactionVoid();
        $void->state = VoidState::FAILED;
        $void->failureReason = new LocalizedString([
            'en-US' => 'Void rejected by gateway',
        ]);

        $this->assertSame(VoidState::FAILED, $void->state);
        $this->assertSame('Void rejected by gateway', $void->failureReason->localize('en-US'));

        $decoded = json_decode((string) $void, true);
        $this->assertArrayHasKey('state', $decoded);
        $this->assertArrayHasKey('failureReason', $decoded);
    }
}
