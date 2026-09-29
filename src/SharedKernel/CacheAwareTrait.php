<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\SharedKernel;

/**
 * Opt-in cache-aside helper for gateways.
 *
 * Caching is entirely optional: {@see \WeArePlanet\PluginCore\Sdk\SdkProvider::getCache()}
 * is nullable, and {@see remember()} degrades to a plain, uncached call when no
 * cache was configured. A client that never supplies a {@see CacheInterface} sees
 * no behavioral change; one that does gets cross-request caching for whatever a
 * gateway chooses to wrap in {@see remember()}.
 *
 * Generic by design: any gateway can adopt this by using the trait and picking
 * its own cache keys and default TTLs, rather than each cacheable entity growing
 * its own bespoke caching decorator.
 *
 * Composes {@see CacheControlTrait}, so {@see remember()} reads its current TTL
 * override and force-refresh state on its own — a call site never passes
 * either. A consuming class gets both traits' behavior from `use CacheAwareTrait;`
 * alone; it does not need to separately `use CacheControlTrait;` too.
 *
 * A broken cache degrades to an uncached call rather than failing the read: the
 * cache is a client-supplied, optional performance accelerator, not a dependency
 * the underlying data read should ever depend on for correctness. Any exception
 * thrown by the cache itself (a connection drop, a misconfigured backend, an
 * illegal key) is caught, logged at warning, and treated as a cache miss —
 * {@see remember()} still returns the freshly computed value, just without
 * caching it; {@see forget()} still returns normally. There is deliberately no
 * `CacheException` a caller needs to handle.
 *
 * Consuming classes must hold a `$sdkProvider` property (every SDK gateway in
 * this codebase already does, since it is also how they resolve SDK services)
 * and a `$logger` property of type {@see \WeArePlanet\PluginCore\Log\LoggerInterface}
 * (every SDK gateway already has this too, via {@see \WeArePlanet\PluginCore\Log\DomainLoggerTrait}).
 */
trait CacheAwareTrait
{
    use CacheControlTrait;

    /**
     * Removes a cached value, if a cache is configured.
     *
     * A no-op when no cache was configured: there is nothing to remove. Also a
     * no-op, rather than a thrown exception, when the cache itself fails to
     * remove it — see the trait docblock on degrading gracefully.
     *
     * @param string $key The cache key to remove.
     * @return void
     */
    protected function forget(string $key): void
    {
        $cache = $this->sdkProvider->getCache();

        if ($cache === null) {
            return;
        }

        try {
            $cache->delete($key);
        } catch (\Throwable $e) {
            $this->logCacheFailure('delete', $key, $e);
        }
    }

    /**
     * Logs a cache backend failure at warning, not error: the underlying read or
     * write this was part of still succeeds (or degrades safely), so nothing an
     * operator needs to treat as an incident — but a persistently misbehaving
     * cache is worth being visible in logs.
     *
     * A missing or not-yet-initialized `$logger` is itself treated as something
     * to degrade past rather than crash on — this method runs inside the one
     * path meant to guarantee a broken cache never breaks the caller, so a
     * misconfigured logger must not reintroduce exactly that risk. `isset()` is
     * what makes this safe to check: unlike a direct read, it never throws for
     * an undeclared property, and for a declared-but-uninitialized typed
     * property (the realistic case: {@see \WeArePlanet\PluginCore\Log\DomainLoggerTrait}
     * used but `initializeLogger()` never called) it evaluates to false instead
     * of triggering PHP's "must not be accessed before initialization" error.
     *
     * @param string $operation The cache operation that failed: 'get', 'set', or
     *        'delete'.
     * @param string $key The cache key involved.
     * @param \Throwable $exception The failure the cache itself threw.
     * @return void
     */
    private function logCacheFailure(string $operation, string $key, \Throwable $exception): void
    {
        if (!isset($this->logger)) {
            return;
        }

        $this->logger->warning(
            'Cache operation failed; degrading to an uncached call.',
            [
                'cacheOperation' => $operation,
                'cacheKey' => $key,
                'errorMessage' => $exception->getMessage(),
                'exception' => $exception,
            ],
        );
    }

    /**
     * Returns a cached value for $key, computing and storing it on a miss.
     *
     * Bypasses the cached value — without ever calling the cache's `get()` —
     * when no cache is configured, or when {@see CacheControlTrait::isForceRefreshEnabled()}
     * is true. Either way, a non-null result is still written back through
     * `set()` when a cache is configured, so a forced refresh benefits later,
     * uncached-refresh callers too, instead of leaving the previous value in
     * place until it expires.
     *
     * @template T
     * @param string $key The cache key, prefixed `weareplanet:` and
     *        unique across the codebase — the cache is a client-supplied,
     *        possibly shared instance, so an unprefixed or generic key (e.g.
     *        `label_descriptors`) risks colliding with something unrelated the
     *        client also stores in it. `weareplanet:<domain>:<entity>`
     *        (e.g. `weareplanet:global_data:label_descriptors`) is the
     *        established shape; keep new keys consistent with it.
     * @param callable(): T $compute Produces the value on a cache miss, or when
     *        the cache is bypassed. Not invoked when $key is already cached and
     *        a refresh has not been forced.
     * @param int $defaultTtl Seconds until the cached value expires, used unless
     *        {@see CacheControlTrait::setCacheTtl()} set an override.
     * @return T The cached or freshly computed value. Always the freshly
     *         computed value when the cache itself failed — see the trait
     *         docblock on degrading gracefully.
     */
    protected function remember(string $key, callable $compute, int $defaultTtl): mixed
    {
        $cache = $this->sdkProvider->getCache();
        $forceRefresh = $this->isForceRefreshEnabled();

        if ($cache !== null && !$forceRefresh) {
            try {
                $cached = $cache->get($key);

                if ($cached !== null) {
                    return $cached;
                }
            } catch (\Throwable $e) {
                $this->logCacheFailure('get', $key, $e);
                // The backend just failed a read; a write right after is likely to
                // fail the same way, so treat it as unavailable for the rest of
                // this call rather than trying and logging a second failure.
                $cache = null;
            }
        }

        $value = $compute();

        if ($cache !== null && $value !== null) {
            try {
                $cache->set($key, $value, $this->getCacheTtl() ?? $defaultTtl);
            } catch (\Throwable $e) {
                $this->logCacheFailure('set', $key, $e);
            }
        }

        return $value;
    }
}
