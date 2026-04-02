<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Document;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Document\RenderedDocument;

class RenderedDocumentTest extends TestCase
{
    public function testToString(): void
    {
        $doc = new RenderedDocument('Invoice', 'application/pdf', 'JVBERi0xLjQKJ...base64data');

        $json = (string) $doc;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertEquals('Invoice', $decoded['title']);
        $this->assertEquals('application/pdf', $decoded['mimeType']);
        $this->assertEquals('JVBERi0xLjQKJ...base64data', $decoded['data']);
    }
}
