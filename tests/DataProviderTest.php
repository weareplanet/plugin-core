<?php

namespace WeArePlanet\PluginCore\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DataProviderTest extends TestCase
{
    /**
     * This is the Data Provider.
     * It returns three sets of arguments for the test below.
     *
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function additionProvider(): array
    {
        return [
            '1 plus 2 is 3'           => [1, 2, 3],
            '5 plus 5 is 10'          => [5, 5, 10],
            'negative 1 plus 1 is 0'  => [-1, 1, 0],
        ];
    }

    /**
     * This test is linked to the provider above.
     *
     * @dataProvider additionProvider
     */
    #[DataProvider('additionProvider')]
    public function testAddition(int $a, int $b, int $expected): void
    {
        $this->assertSame($expected, $a + $b);
    }
}

// use PHPUnit\Framework\Attributes\DataProviderExternal;
// use PHPUnit\Framework\TestCase;

// final class DataProviderTest extends TestCase
// {
//     #[DataProviderExternal(ExternalDataProvider::class, 'additionProvider')]
//     public function testAdd(int $a, int $b, int $expected): void
//     {
//         $this->assertSame($expected, $a + $b);
//     }
// }

// final class ExternalDataProvider
// {
//     public static function additionProvider(): array
//     {
//         return [
//             [0, 0, 0],
//             [0, 1, 1],
//             [1, 0, 1],
//             // [1, 1, 3],
//         ];
//     }
// }
