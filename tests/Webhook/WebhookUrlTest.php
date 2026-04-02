<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Webhook\WebhookUrl;

class WebhookUrlTest extends TestCase
{
    public function testToString(): void
    {
        $url = new WebhookUrl(1, 'Test Webhook', 'https://example.com/webhook', 1);

        $json = (string) $url;
        $this->assertJson($json);
        $this->assertJsonStringEqualsJsonString('{"id":1,"name":"Test Webhook","url":"https://example.com/webhook","state":1}', $json);
    }
}
