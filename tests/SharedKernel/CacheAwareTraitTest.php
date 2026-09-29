<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\SharedKernel;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\SharedKernel\CacheInterface;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\SharedKernel\CacheAwareTrait;

/**
 * Direct unit coverage of {@see CacheAwareTrait} in isolation, independent of any
 * one gateway that happens to adopt it.
 */
class CacheAwareTraitTest extends TestCase
{
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkProvider $sdkProvider;
    private object $subject;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // An anonymous class using the trait, exposing its protected remember()/
        // forget() for the test to call directly. setCacheTtl()/setForceRefresh()
        // are already public: CacheAwareTrait composes CacheControlTrait, so this
        // class gets them without using it separately. $logger mirrors what
        // DomainLoggerTrait gives every real adopter.
        $this->subject = new class ($this->sdkProvider, $this->logger) {
            use CacheAwareTrait;

            public function __construct(
                private readonly SdkProvider $sdkProvider,
                private readonly LoggerInterface $logger,
            ) {
            }

            public function callRemember(string $key, callable $compute, int $defaultTtl = 60): mixed
            {
                return $this->remember($key, $compute, $defaultTtl);
            }

            public function callForget(string $key): void
            {
                $this->forget($key);
            }
        };
    }

    public function testRememberCallsComputeEveryTimeWhenNoCacheIsConfigured(): void
    {
        // getCache() is left unstubbed on the mock, so it returns null: exactly a
        // client that never configured one.
        $calls = 0;
        $compute = function () use (&$calls): string {
            $calls++;

            return 'value';
        };

        $this->assertSame('value', $this->subject->callRemember('key', $compute));
        $this->assertSame('value', $this->subject->callRemember('key', $compute));
        $this->assertSame(2, $calls);
    }

    public function testRememberComputesOnceAndServesTheCacheOnASecondCall(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnOnConsecutiveCalls(null, 'value');
        $cache->expects($this->once())->method('set')->with('key', 'value', 60);
        $this->sdkProvider->method('getCache')->willReturn($cache);

        $calls = 0;
        $compute = function () use (&$calls): string {
            $calls++;

            return 'value';
        };

        $this->assertSame('value', $this->subject->callRemember('key', $compute));
        $this->assertSame('value', $this->subject->callRemember('key', $compute));
        $this->assertSame(1, $calls, 'compute() must not run again once the value is cached.');
    }

    public function testRememberDoesNotCacheANullResult(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects($this->never())->method('set');
        $this->sdkProvider->method('getCache')->willReturn($cache);

        $this->assertNull($this->subject->callRemember('key', fn () => null));
    }

    public function testRememberUsesTheGivenDefaultTtlWhenNoOverrideWasSet(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects($this->once())->method('set')->with('key', 'value', 60);
        $this->sdkProvider->method('getCache')->willReturn($cache);

        $this->subject->callRemember('key', fn () => 'value', 60);
    }

    public function testRememberUsesSetCacheTtlOverTheGivenDefault(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        // setCacheTtl() takes priority: the given default (60) must be ignored.
        $cache->expects($this->once())->method('set')->with('key', 'value', 3600);
        $this->sdkProvider->method('getCache')->willReturn($cache);

        $this->subject->setCacheTtl(3600);
        $this->subject->callRemember('key', fn () => 'value', 60);
    }

    public function testRememberWithForceRefreshEnabledSkipsTheCachedValueAndRepopulatesIt(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        // get() must not even be consulted: forceRefresh bypasses the read entirely.
        $cache->expects($this->never())->method('get');
        $cache->expects($this->once())->method('set')->with('key', 'fresh', 60);
        $this->sdkProvider->method('getCache')->willReturn($cache);

        $this->subject->setForceRefresh();

        $this->assertSame('fresh', $this->subject->callRemember('key', fn () => 'fresh'));
    }

    public function testRememberStopsBypassingTheCacheOnceForceRefreshIsTurnedOff(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn('cached');
        $this->sdkProvider->method('getCache')->willReturn($cache);

        $this->subject->setForceRefresh(true);
        $this->subject->setForceRefresh(false);

        $this->assertSame('cached', $this->subject->callRemember('key', fn () => 'fresh'));
    }

    // ---------------------------------------------------------------------
    // Graceful degradation: a broken cache must never break the underlying
    // read/write it was asked to accelerate.
    // ---------------------------------------------------------------------

    public function testRememberFallsBackToComputeWhenGetThrows(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $exception = new \RuntimeException('Connection refused');
        $cache->method('get')->willThrowException($exception);
        // A read that just failed is not retried for the write either.
        $cache->expects($this->never())->method('set');
        $this->sdkProvider->method('getCache')->willReturn($cache);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('degrading'),
                $this->callback(function (array $context) use ($exception): bool {
                    $this->assertSame('get', $context['cacheOperation']);
                    $this->assertSame('key', $context['cacheKey']);
                    $this->assertSame($exception, $context['exception']);

                    return true;
                }),
            );

        $this->assertSame('fresh', $this->subject->callRemember('key', fn () => 'fresh'));
    }

    public function testRememberStillReturnsTheFreshValueWhenSetThrows(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $exception = new \RuntimeException('Disk full');
        $cache->method('set')->willThrowException($exception);
        $this->sdkProvider->method('getCache')->willReturn($cache);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('degrading'),
                $this->callback(function (array $context) use ($exception): bool {
                    $this->assertSame('set', $context['cacheOperation']);
                    $this->assertSame($exception, $context['exception']);

                    return true;
                }),
            );

        $this->assertSame('fresh', $this->subject->callRemember('key', fn () => 'fresh'));
    }

    public function testForgetIsANoOpAndLogsAWarningWhenDeleteThrows(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $exception = new \RuntimeException('Connection refused');
        $cache->method('delete')->willThrowException($exception);
        $this->sdkProvider->method('getCache')->willReturn($cache);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('degrading'),
                $this->callback(function (array $context) use ($exception): bool {
                    $this->assertSame('delete', $context['cacheOperation']);
                    $this->assertSame($exception, $context['exception']);

                    return true;
                }),
            );

        // No exception propagates.
        $this->subject->callForget('key');
    }

    /**
     * A consuming class that forgets to wire up a $logger — the realistic case
     * being DomainLoggerTrait used but initializeLogger() never called, leaving
     * the typed property declared yet uninitialized — must not turn a cache
     * failure into a crash. That would reintroduce exactly the fragility this
     * trait exists to remove, and at the worst possible moment: inside the
     * failure path itself.
     */
    public function testRememberDegradesSilentlyWhenTheConsumingClassesLoggerWasNeverInitialized(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willThrowException(new \RuntimeException('Connection refused'));
        $this->sdkProvider->method('getCache')->willReturn($cache);

        $subjectWithoutLogger = new class ($this->sdkProvider) {
            use CacheAwareTrait;

            private LoggerInterface $logger;

            public function __construct(private readonly SdkProvider $sdkProvider)
            {
            }

            // A real assignment exists (DomainLoggerTrait::initializeLogger() plays
            // this role in production), but this test never calls it — $logger
            // stays uninitialized, mirroring a class that forgot to.
            public function wireLogger(LoggerInterface $logger): void
            {
                $this->logger = $logger;
            }

            public function callRemember(string $key, callable $compute, int $defaultTtl = 60): mixed
            {
                return $this->remember($key, $compute, $defaultTtl);
            }
        };

        // No exception, despite there being nothing to log the failure to.
        $this->assertSame('fresh', $subjectWithoutLogger->callRemember('key', fn () => 'fresh'));
    }

    public function testForgetDeletesTheCachedEntryWhenACacheIsConfigured(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('delete')->with('key');
        $this->sdkProvider->method('getCache')->willReturn($cache);

        $this->subject->callForget('key');
    }

    public function testForgetIsANoOpWhenNoCacheIsConfigured(): void
    {
        // No exception, and nothing to assert on beyond "did not throw" — there is
        // no cache instance for a delete() expectation to attach to.
        $this->subject->callForget('key');

        $this->addToAssertionCount(1);
    }
}
