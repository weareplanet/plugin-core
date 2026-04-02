<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Webhook\WebhookConfig;

class WebhookConfigTest extends TestCase
{
    public function testToString(): void
    {
        $config = new WebhookConfig('https://example.com/api', 'Main Config', 2, 'COMPLETED');

        $json = (string) $config;
        $this->assertJson($json);
        $this->assertJsonStringEqualsJsonString('{"url":"https://example.com/api","name":"Main Config","entityId":2,"eventStateId":"COMPLETED"}', $json);
    }
}
