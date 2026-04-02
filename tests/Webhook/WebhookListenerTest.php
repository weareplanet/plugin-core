<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Webhook\WebhookListener;

class WebhookListenerTest extends TestCase
{
    public function testToString(): void
    {
        $listener = new WebhookListener(100, 'Transaction Update', 1, ['AUTHORIZED', 'COMPLETED']);

        $json = (string) $listener;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertEquals(100, $decoded['id']);
        $this->assertEquals('Transaction Update', $decoded['name']);
        $this->assertEquals(1, $decoded['entityId']);
        $this->assertEquals(['AUTHORIZED', 'COMPLETED'], $decoded['entityStates']);
    }
}
