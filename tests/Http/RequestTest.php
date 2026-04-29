<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Http;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Http\Request;

class RequestTest extends TestCase
{
    public function testCreate(): void
    {
        $headers = ['Content-Type' => 'application/json', 'X-Custom-Header' => 'value'];
        $body = ['foo' => 'bar'];
        $rawBody = (string) json_encode($body);

        $request = Request::create($headers, $body, $rawBody);

        $this->assertEquals($body, $request->body);
        $this->assertEquals($rawBody, $request->getRawBody());
        $this->assertEquals('value', $request->getHeader('X-Custom-Header'));
        $this->assertEquals('value', $request->getHeader('x-custom-header')); // check case-insensitivity
    }

    public function testToString(): void
    {
        // For testing, since constructor is private and fromWordPress reads superglobals
        $_SERVER['HTTP_SOME_KEY'] = 'some_value';

        $request = Request::fromWordPress();

        $json = (string) $request;
        $this->assertJson($json);

        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('body', $decoded);
    }
}
