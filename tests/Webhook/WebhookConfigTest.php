<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Webhook\WebhookConfig;

class WebhookConfigTest extends TestCase
{
    public function testToString(): void
    {
        $config = new WebhookConfig('https://example.com/api', 'Main Config', \WeArePlanet\PluginCore\Webhook\Enum\WebhookListener::TRANSACTION, ['COMPLETED']);

        $json = (string) $config;
        $this->assertJson($json);
        $this->assertJsonStringEqualsJsonString('{"url":"https://example.com/api","name":"Main Config","entity":1472041829003,"eventStates":["COMPLETED"]}', $json);
    }
}
