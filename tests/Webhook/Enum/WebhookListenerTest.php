<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook\Enum;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;

class WebhookListenerTest extends TestCase
{
    public function testFromTechnicalNameReturnsCorrectCase(): void
    {
        // --- Act ---
        $case = WebhookListener::fromTechnicalName('Transaction');

        // --- Assert ---
        $this->assertSame(WebhookListener::TRANSACTION, $case);
    }

    public function testFromTechnicalNameThrowsValueErrorForInvalidName(): void
    {
        // --- Assert ---
        // Expect a ValueError for a name that doesn't exist.
        $this->expectException(\ValueError::class);

        // --- Act ---
        WebhookListener::fromTechnicalName('InvalidTechnicalName');
    }
    public function testGetTechnicalNameReturnsCorrectString(): void
    {
        // --- Act ---
        $technicalName = WebhookListener::TRANSACTION->getTechnicalName();

        // --- Assert ---
        $this->assertSame('Transaction', $technicalName);
    }
}
