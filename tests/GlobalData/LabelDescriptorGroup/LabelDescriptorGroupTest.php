<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\GlobalData\LabelDescriptorGroup;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\GlobalData\LabelDescriptorGroup\LabelDescriptorGroup;
use WeArePlanet\PluginCore\GlobalData\LabelDescriptorGroup\LabelDescriptorGroupCollection;
use WeArePlanet\PluginCore\Localization\LocalizedString;

class LabelDescriptorGroupTest extends TestCase
{
    public function testToString(): void
    {
        $group = new LabelDescriptorGroup(
            id: 4,
            name: new LocalizedString(['en-US' => 'Card']),
            weight: 10,
        );

        $json = (string) $group;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertSame(4, $decoded['id']);
        $this->assertSame(10, $decoded['weight']);
        $this->assertSame('Card', $decoded['name']['en-US']);
    }

    public function testGroupIsImmutable(): void
    {
        $group = new LabelDescriptorGroup(4, new LocalizedString('Card'), 10);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line Intentionally writing to a readonly property.
        $group->id = 5;
    }

    public function testFindByIdReturnsTheMatchingGroup(): void
    {
        $card = new LabelDescriptorGroup(4, new LocalizedString('Card'), 10);
        $collection = new LabelDescriptorGroupCollection(
            $card,
            new LabelDescriptorGroup(7, new LocalizedString('Authorisation'), 20),
        );

        $this->assertSame($card, $collection->findById(4));
        $this->assertNull($collection->findById(99));
    }
}
