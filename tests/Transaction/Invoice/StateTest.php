<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction\Invoice;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Transaction\Invoice\State;

class StateTest extends TestCase
{
    /**
     * @return array<string, array{0: State, 1: bool}>
     */
    public static function blocksCaptureProvider(): array
    {
        return [
            'CREATE does not block capture' => [State::CREATE, false],
            'OPEN blocks capture' => [State::OPEN, true],
            'OVERDUE blocks capture' => [State::OVERDUE, true],
            'PAID does not block capture' => [State::PAID, false],
            'CANCELED does not block capture' => [State::CANCELED, false],
            'DERECOGNIZED does not block capture' => [State::DERECOGNIZED, false],
            'NOT_APPLICABLE does not block capture' => [State::NOT_APPLICABLE, false],
        ];
    }

    #[DataProvider('blocksCaptureProvider')]
    public function testBlocksCapture(State $state, bool $expected): void
    {
        $this->assertSame($expected, $state->blocksCapture());
    }
}
