<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Transaction\State;

class StateTest extends TestCase
{
    /**
     * @return array<string, array{0: State, 1: bool}>
     */
    public static function isPaidLikeProvider(): array
    {
        return [
            'CREATE is not paid-like' => [State::CREATE, false],
            'PENDING is not paid-like' => [State::PENDING, false],
            'CONFIRMED is not paid-like' => [State::CONFIRMED, false],
            'PROCESSING is not paid-like' => [State::PROCESSING, false],
            'FAILED is not paid-like' => [State::FAILED, false],
            'VOIDED is not paid-like' => [State::VOIDED, false],
            'AUTHORIZED is paid-like' => [State::AUTHORIZED, true],
            'COMPLETED is paid-like' => [State::COMPLETED, true],
            'FULFILL is paid-like' => [State::FULFILL, true],
            'DECLINE is not paid-like' => [State::DECLINE, false],
        ];
    }

    #[DataProvider('isPaidLikeProvider')]
    public function testIsPaidLike(State $state, bool $expected): void
    {
        $this->assertSame($expected, $state->isPaidLike());
    }

    /**
     * @return array<string, array{0: State, 1: bool}>
     */
    public static function isInvoiceDownloadAllowedProvider(): array
    {
        return [
            'CREATE cannot download invoice' => [State::CREATE, false],
            'PENDING cannot download invoice' => [State::PENDING, false],
            'CONFIRMED cannot download invoice' => [State::CONFIRMED, false],
            'PROCESSING cannot download invoice' => [State::PROCESSING, false],
            'FAILED cannot download invoice' => [State::FAILED, false],
            'AUTHORIZED cannot download invoice' => [State::AUTHORIZED, false],
            'VOIDED cannot download invoice' => [State::VOIDED, false],
            'COMPLETED can download invoice' => [State::COMPLETED, true],
            'FULFILL can download invoice' => [State::FULFILL, true],
            'DECLINE can download invoice' => [State::DECLINE, true],
        ];
    }

    #[DataProvider('isInvoiceDownloadAllowedProvider')]
    public function testIsInvoiceDownloadAllowed(State $state, bool $expected): void
    {
        $this->assertSame($expected, $state->isInvoiceDownloadAllowed());
    }

    /**
     * @return array<string, array{0: State, 1: bool}>
     */
    public static function isPackingSlipDownloadAllowedProvider(): array
    {
        return [
            'CREATE cannot download packing slip' => [State::CREATE, false],
            'PENDING cannot download packing slip' => [State::PENDING, false],
            'CONFIRMED cannot download packing slip' => [State::CONFIRMED, false],
            'PROCESSING cannot download packing slip' => [State::PROCESSING, false],
            'FAILED cannot download packing slip' => [State::FAILED, false],
            'AUTHORIZED cannot download packing slip' => [State::AUTHORIZED, false],
            'VOIDED cannot download packing slip' => [State::VOIDED, false],
            'COMPLETED cannot download packing slip' => [State::COMPLETED, false],
            'FULFILL can download packing slip' => [State::FULFILL, true],
            'DECLINE cannot download packing slip' => [State::DECLINE, false],
        ];
    }

    #[DataProvider('isPackingSlipDownloadAllowedProvider')]
    public function testIsPackingSlipDownloadAllowed(State $state, bool $expected): void
    {
        $this->assertSame($expected, $state->isPackingSlipDownloadAllowed());
    }

    /**
     * @return array<string, array{0: State, 1: bool}>
     */
    public static function allowsCompletionProvider(): array
    {
        return [
            'CREATE does not allow completion' => [State::CREATE, false],
            'PENDING does not allow completion' => [State::PENDING, false],
            'CONFIRMED does not allow completion' => [State::CONFIRMED, false],
            'PROCESSING does not allow completion' => [State::PROCESSING, false],
            'FAILED does not allow completion' => [State::FAILED, false],
            'AUTHORIZED allows completion' => [State::AUTHORIZED, true],
            'VOIDED does not allow completion' => [State::VOIDED, false],
            'COMPLETED does not allow completion' => [State::COMPLETED, false],
            'FULFILL does not allow completion' => [State::FULFILL, false],
            'DECLINE does not allow completion' => [State::DECLINE, false],
        ];
    }

    /**
     * @return array<string, array{0: State, 1: bool}>
     */
    public static function allowsInvoiceManipulationProvider(): array
    {
        return [
            'CREATE does not allow invoice manipulation' => [State::CREATE, false],
            'PENDING does not allow invoice manipulation' => [State::PENDING, false],
            'CONFIRMED does not allow invoice manipulation' => [State::CONFIRMED, false],
            'PROCESSING does not allow invoice manipulation' => [State::PROCESSING, false],
            'FAILED does not allow invoice manipulation' => [State::FAILED, false],
            'AUTHORIZED allows invoice manipulation' => [State::AUTHORIZED, true],
            'VOIDED does not allow invoice manipulation' => [State::VOIDED, false],
            'COMPLETED does not allow invoice manipulation' => [State::COMPLETED, false],
            'FULFILL does not allow invoice manipulation' => [State::FULFILL, false],
            'DECLINE does not allow invoice manipulation' => [State::DECLINE, false],
        ];
    }

    #[DataProvider('allowsCompletionProvider')]
    public function testAllowsCompletion(State $state, bool $expected): void
    {
        $this->assertSame($expected, $state->allowsCompletion());
    }

    #[DataProvider('allowsInvoiceManipulationProvider')]
    public function testAllowsInvoiceManipulation(State $state, bool $expected): void
    {
        $this->assertSame($expected, $state->allowsInvoiceManipulation());
    }

    public function testGetPaidLikeValues(): void
    {
        $this->assertSame(['AUTHORIZED', 'COMPLETED', 'FULFILL'], State::getPaidLikeValues());
    }
}
