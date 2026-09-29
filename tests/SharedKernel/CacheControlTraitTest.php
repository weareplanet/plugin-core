<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\SharedKernel;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\SharedKernel\CacheControlTrait;

/**
 * Direct unit coverage of {@see CacheControlTrait} in isolation, independent of
 * any one service that happens to adopt it.
 */
class CacheControlTraitTest extends TestCase
{
    /**
     * An anonymous class using the trait, exposing its protected accessors for
     * the test to call directly.
     */
    private function makeSubject(?int $defaultTtl = null): object
    {
        return new class ($defaultTtl) {
            use CacheControlTrait;

            public function __construct(?int $defaultTtl)
            {
                $this->initializeCacheControl($defaultTtl);
            }

            public function exposedGetCacheTtl(): ?int
            {
                return $this->getCacheTtl();
            }

            public function exposedIsForceRefreshEnabled(): bool
            {
                return $this->isForceRefreshEnabled();
            }
        };
    }

    public function testConstructorDefaultTtlIsUsedUntilOverridden(): void
    {
        $subject = $this->makeSubject(3600);

        $this->assertSame(3600, $subject->exposedGetCacheTtl());
    }
    public function testDefaultsToNoTtlAndNoForceRefreshWhenConstructedWithoutADefault(): void
    {
        $subject = $this->makeSubject();

        $this->assertNull($subject->exposedGetCacheTtl());
        $this->assertFalse($subject->exposedIsForceRefreshEnabled());
    }

    public function testSetCacheTtlNullDefersBackToTheDelegatesOwnDefault(): void
    {
        $subject = $this->makeSubject(3600);

        $subject->setCacheTtl(null);

        $this->assertNull($subject->exposedGetCacheTtl());
    }

    public function testSetCacheTtlOverridesTheConstructorDefault(): void
    {
        $subject = $this->makeSubject(3600);

        $subject->setCacheTtl(60);

        $this->assertSame(60, $subject->exposedGetCacheTtl());
    }

    public function testSetForceRefreshDefaultsToTrue(): void
    {
        $subject = $this->makeSubject();

        $subject->setForceRefresh();

        $this->assertTrue($subject->exposedIsForceRefreshEnabled());
    }

    public function testSetForceRefreshIsStickyUntilExplicitlyTurnedOff(): void
    {
        $subject = $this->makeSubject();

        $subject->setForceRefresh();

        $this->assertTrue($subject->exposedIsForceRefreshEnabled());
        $this->assertTrue($subject->exposedIsForceRefreshEnabled(), 'A second read must still see it enabled.');

        $subject->setForceRefresh(false);

        $this->assertFalse($subject->exposedIsForceRefreshEnabled());
    }
}
