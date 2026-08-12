<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Http;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Http\Request;

class RequestTest extends TestCase
{
    public function testAListValuedHeaderIsFlattenedToItsFirstValue(): void
    {
        // Symfony's HttpFoundation returns array<string, string[]> from headers->all(),
        // which fromSymfonyRequest() passes straight through. Before normalization this
        // made getHeader() fatal with a TypeError on every Symfony-based platform, so
        // signature validation could not even be attempted there.
        $request = Request::create(['X-Signature' => ['abc123']], [], '{}');

        $this->assertSame('abc123', $request->getHeader('x-signature'));
    }

    public function testAMissingHeaderIsNull(): void
    {
        $request = Request::create(['X-Signature' => 'abc123'], [], '{}');

        $this->assertNull($request->getHeader('x-absent'));
    }

    public function testAnEmptyListYieldsAnEmptyStringRatherThanFailing(): void
    {
        // A degenerate header is a malformed request, not a crash: callers treat the
        // empty string as "absent" the same way they treat null.
        $request = Request::create(['X-Signature' => []], [], '{}');

        $this->assertSame('', $request->getHeader('x-signature'));
    }

    public function testAPlainStringHeaderIsUnaffected(): void
    {
        $request = Request::create(['X-Signature' => 'abc123'], [], '{}');

        $this->assertSame('abc123', $request->getHeader('x-signature'));
    }

    public function testBodyValuesAreReadFromTheParsedPayload(): void
    {
        $request = Request::create([], ['spaceId' => 1, 'entityId' => 42], '{}');

        $this->assertSame(1, $request->get('spaceId'));
        $this->assertSame(42, $request->get('entityId'));
        $this->assertNull($request->get('absent'));
        $this->assertSame('fallback', $request->get('absent', 'fallback'));
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $request = Request::create(['X-SIGNATURE' => 'abc123'], [], '{}');

        $this->assertSame('abc123', $request->getHeader('x-signature'));
        $this->assertSame('abc123', $request->getHeader('X-Signature'));
    }

    public function testTheRawBodyIsPreservedExactly(): void
    {
        // Signature validation hashes these bytes, so they must survive untouched —
        // whitespace and key order included.
        $rawBody = '{ "spaceId": 1,  "entityId": 42 }';
        $request = Request::create([], ['spaceId' => 1, 'entityId' => 42], $rawBody);

        $this->assertSame($rawBody, $request->getRawBody());
    }
}
