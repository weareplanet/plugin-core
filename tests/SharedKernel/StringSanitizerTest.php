<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\SharedKernel;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\SharedKernel\StringSanitizer;

class StringSanitizerTest extends TestCase
{
    public function testTruncateShortensOversizedStrings(): void
    {
        $this->assertSame('12345', StringSanitizer::truncate('123456789', 5));
    }

    public function testTruncateLeavesShorterStringsUntouched(): void
    {
        $this->assertSame('abc', StringSanitizer::truncate('abc', 5));
    }

    public function testTruncateIsMultiByteSafe(): void
    {
        // Each of these is one character but multiple bytes in UTF-8.
        $value = 'äöü漢字';
        $this->assertSame('äöü', StringSanitizer::truncate($value, 3));
    }

    public function testStripLineBreaksReplacesCarriageReturnAndNewline(): void
    {
        $this->assertSame('a b c', StringSanitizer::stripLineBreaks("a\rb\nc"));
    }

    public function testStripLineBreaksReplacesEachCharacterOfACrlfPair(): void
    {
        // \r and \n are each replaced independently, so a CRLF pair becomes two spaces.
        $this->assertSame('a  b', StringSanitizer::stripLineBreaks("a\r\nb"));
    }

    public function testStripLineBreaksLeavesOtherWhitespaceUntouched(): void
    {
        $this->assertSame("a b\tc", StringSanitizer::stripLineBreaks("a b\tc"));
    }
}
