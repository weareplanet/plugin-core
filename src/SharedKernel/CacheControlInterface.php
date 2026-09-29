<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\SharedKernel;

/**
 * Dynamic control over a class's caching behavior.
 *
 * Pairs with {@see CacheControlTrait}, which is the usual way to satisfy this:
 * a class implementing an interface that extends this one can `use
 * CacheControlTrait;` and both methods are already provided. Any interface
 * whose implementations may cache their reads can extend this one, the same way
 * {@see \WeArePlanet\PluginCore\GlobalData\GlobalDataGatewayInterface}
 * does, instead of redeclaring the same two methods itself.
 */
interface CacheControlInterface
{
    /**
     * Sets how long a result may be cached for, in seconds, when a cache is
     * configured.
     *
     * Applies to every subsequent call, not retroactively to anything already
     * cached under a previous TTL.
     *
     * @param int|null $ttl The TTL, in seconds, or null to defer to the
     *        implementation's own default.
     * @return void
     */
    public function setCacheTtl(?int $ttl): void;

    /**
     * Enables or disables bypassing the cache on every subsequent call.
     *
     * Sticky, not one-shot: once enabled, every call bypasses — and
     * repopulates — the cache until this is called again with false.
     *
     * @param bool $forceRefresh True to bypass the cache from now on.
     * @return void
     */
    public function setForceRefresh(bool $forceRefresh = true): void;
}
