<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Http;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Http\Request;

/**
 * Unit tests for the Request class.
 */
class RequestTest extends TestCase
{
    /**
     * Test the manual creation of a Request instance.
     *
     * @return void
     */
    public function testCreate(): void
    {
        $headers = ['Content-Type' => 'application/json', 'X-Custom-Header' => 'value'];
        $body = ['foo' => 'bar'];
        $rawBody = (string) json_encode($body, JSON_THROW_ON_ERROR);

        $request = Request::create($headers, $body, $rawBody);

        $this->assertEquals($body, $request->body);
        $this->assertEquals($rawBody, $request->getRawBody());
        $this->assertEquals('value', $request->getHeader('X-Custom-Header'));
        $this->assertEquals('value', $request->getHeader('x-custom-header')); // check case-insensitivity
    }

    /**
     * Test the JSON string representation of the Request.
     *
     * @return void
     */
    public function testToString(): void
    {
        // For testing, since constructor is private and fromWordPress reads superglobals.
        $_SERVER['HTTP_SOME_KEY'] = 'some_value';

        $request = Request::fromWordPress();

        $json = (string) $request;
        $this->assertJson($json);

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('body', $decoded);
    }
}
