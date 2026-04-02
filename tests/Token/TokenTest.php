<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Token;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\State;

class TokenTest extends TestCase
{
    public function testToString(): void
    {
        $token = new Token();
        $token->id = 700;
        $token->spaceId = 1;
        $token->state = State::ACTIVE;
        $token->version = 1;

        $json = (string) $token;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertEquals(700, $decoded['id']);
        $this->assertEquals(1, $decoded['spaceId']);
        $this->assertEquals(1, $decoded['version']);
        $this->assertArrayHasKey('state', $decoded);
    }
}
