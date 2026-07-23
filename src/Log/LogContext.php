<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Log;

/**
 * Declares the logging domain of a class for the Hybrid Logging Pattern.
 *
 * Classes tagged with this attribute (and using {@see DomainLoggerTrait}) get
 * their logger wrapped in a {@see DomainAwareLogger}, which prefixes every
 * message with `[Domain] ` and merges the domain/subdomain/source keys into
 * the log context automatically.
 *
 * Shop plugins can reuse the pattern by tagging their own classes and setting
 * `source` to their plugin identifier (defaults to 'core').
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class LogContext
{
    public function __construct(
        public readonly string $domain,
        public readonly ?string $subdomain = null,
        public readonly string $source = 'core',
    ) {
    }
}
