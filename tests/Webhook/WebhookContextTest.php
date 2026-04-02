<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Webhook\WebhookContext;

class WebhookContextTest extends TestCase
{
    public function testToString(): void
    {
        $context = new WebhookContext('AUTHORIZED', 'PENDING', 12345, 1);

        $json = (string) $context;
        $this->assertJson($json);
        $this->assertJsonStringEqualsJsonString('{"remoteState":"AUTHORIZED","lastProcessedState":"PENDING","entityId":12345,"spaceId":1}', $json);
    }
}
