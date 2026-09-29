<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\SharedKernel;

/**
 * Lets a consumer dynamically control the caching behavior of a service, without
 * that service's own read methods carrying cache-specific parameters that have
 * nothing to do with what those methods actually return.
 *
 * This trait holds no cache itself and makes no API call — it only tracks two
 * knobs a caller can turn. The class using it is responsible for reading
 * {@see getCacheTtl()} / {@see isForceRefreshEnabled()} and forwarding them to
 * whatever it delegates the actual caching to underneath (typically a gateway
 * using {@see CacheAwareTrait}).
 *
 * Call {@see initializeCacheControl()} from the constructor, mirroring
 * {@see \WeArePlanet\PluginCore\Log\DomainLoggerTrait::initializeLogger()}.
 * Any service that reads cacheable data can adopt this the same way, not just
 * {@see \WeArePlanet\PluginCore\GlobalData\GlobalDataService}.
 */
trait CacheControlTrait
{
    private ?int $cacheTtl = null;
    private bool $forceRefresh = false;

    /**
     * @param int|null $defaultTtl The default TTL, in seconds, forwarded to
     *        whatever this class delegates caching to until {@see setCacheTtl()}
     *        overrides it. Null defers to that delegate's own default.
     */
    protected function initializeCacheControl(?int $defaultTtl = null): void
    {
        $this->cacheTtl = $defaultTtl;
    }

    /**
     * Sets how long a result may be cached for, in seconds.
     *
     * Takes effect on every call made after this one, not retroactively on
     * anything already cached under the previous TTL.
     *
     * @param int|null $ttl The TTL, in seconds, or null to defer back to the
     *        delegate's own default.
     * @return void
     */
    public function setCacheTtl(?int $ttl): void
    {
        $this->cacheTtl = $ttl;
    }

    /**
     * Enables or disables bypassing the cache on every call made after this one.
     *
     * This is sticky, not one-shot: once enabled, every call bypasses — and
     * repopulates — the cache until this is called again with false. Turn it
     * back off once the reason for forcing a refresh no longer applies; leaving
     * it on defeats caching for as long as it stays set.
     *
     * @param bool $forceRefresh True to bypass the cache from now on.
     * @return void
     */
    public function setForceRefresh(bool $forceRefresh = true): void
    {
        $this->forceRefresh = $forceRefresh;
    }

    /**
     * @return int|null The configured TTL, in seconds, or null for the delegate's
     *         own default.
     */
    protected function getCacheTtl(): ?int
    {
        return $this->cacheTtl;
    }

    /**
     * @return bool Whether calls should currently bypass the cache.
     */
    protected function isForceRefreshEnabled(): bool
    {
        return $this->forceRefresh;
    }
}
