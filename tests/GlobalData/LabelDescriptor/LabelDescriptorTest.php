<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\GlobalData\LabelDescriptor;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\GlobalData\LabelDescriptor\LabelDescriptor;
use WeArePlanet\PluginCore\GlobalData\LabelDescriptor\LabelDescriptorCollection;
use WeArePlanet\PluginCore\Localization\LocalizedString;

class LabelDescriptorTest extends TestCase
{
    public function testDescriptorIsImmutable(): void
    {
        $descriptor = new LabelDescriptor(1001, new LocalizedString('Card Brand'));

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line Intentionally writing to a readonly property.
        $descriptor->id = 1002;
    }

    public function testFindByGroupReturnsOnlyDescriptorsOfThatGroup(): void
    {
        $collection = new LabelDescriptorCollection(
            new LabelDescriptor(1001, new LocalizedString('Card Brand'), 4),
            new LabelDescriptor(1002, new LocalizedString('Card Number'), 4),
            new LabelDescriptor(1003, new LocalizedString('Auth Code'), 7),
            new LabelDescriptor(1004, new LocalizedString('Ungrouped')),
        );

        $groupFour = $collection->findByGroup(4);
        $this->assertCount(2, $groupFour);
        $this->assertSame([1001, 1002], array_map(static fn (LabelDescriptor $d) => $d->id, $groupFour));

        $this->assertCount(1, $collection->findByGroup(7));
        $this->assertSame([], $collection->findByGroup(99));
    }

    public function testFindByIdReturnsTheMatchingDescriptor(): void
    {
        $brand = new LabelDescriptor(1001, new LocalizedString('Card Brand'), 4);
        $collection = new LabelDescriptorCollection(
            $brand,
            new LabelDescriptor(1002, new LocalizedString('Card Number'), 4),
        );

        $this->assertSame($brand, $collection->findById(1001));
        $this->assertNull($collection->findById(9999));
    }

    public function testOptionalPropertiesDefaultToNull(): void
    {
        $descriptor = new LabelDescriptor(1001, new LocalizedString('Card Brand'));

        $this->assertNull($descriptor->groupId);
        $this->assertSame(0, $descriptor->weight);
        $this->assertNull($descriptor->category);
        $this->assertNull($descriptor->type);
    }
    public function testToString(): void
    {
        $descriptor = new LabelDescriptor(
            id: 1001,
            name: new LocalizedString(['en-US' => 'Card Brand']),
            groupId: 4,
            weight: 10,
            category: 'HUMAN',
            type: 2,
        );

        $json = (string) $descriptor;
        $this->assertJson($json);
        $decoded = json_decode($json, true);

        $this->assertSame(1001, $decoded['id']);
        $this->assertSame(4, $decoded['groupId']);
        $this->assertSame(10, $decoded['weight']);
        $this->assertSame('HUMAN', $decoded['category']);
        $this->assertSame(2, $decoded['type']);
        $this->assertSame('Card Brand', $decoded['name']['en-US']);
    }
}
