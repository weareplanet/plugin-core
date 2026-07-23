<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\LineItem;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\LineItem\LineItemAttribute;

class LineItemAttributeTest extends TestCase
{
    public function testConstructorLeavesAlreadyValidValuesUntouched(): void
    {
        $attribute = new LineItemAttribute('option144', 'Size', 'M');

        $this->assertSame('option144', $attribute->id);
        $this->assertSame('Size', $attribute->label);
        $this->assertSame('M', $attribute->value);
    }

    public function testConstructorLowercasesTheKey(): void
    {
        $attribute = new LineItemAttribute('Option_144', 'Size', 'M');

        $this->assertSame('option144', $attribute->id);
    }

    public function testConstructorStripsNonAlphanumericCharactersFromTheKey(): void
    {
        $attribute = new LineItemAttribute('option-144!', 'Size', 'M');

        $this->assertSame('option144', $attribute->id);
    }

    public function testConstructorTruncatesTheKeyTo40Characters(): void
    {
        $attribute = new LineItemAttribute(str_repeat('a', 50), 'Size', 'M');

        $this->assertSame(str_repeat('a', 40), $attribute->id);
    }

    public function testConstructorTruncatesTheValueTo512Characters(): void
    {
        $attribute = new LineItemAttribute('option144', 'Size', str_repeat('a', 600));

        $this->assertSame(str_repeat('a', 512), $attribute->value);
    }
}
