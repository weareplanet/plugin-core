<?php

namespace WeArePlanet\PluginCore\SharedKernel;

// If the official PSR-16 interface exists, our interface simply extends it.
if (interface_exists(\Psr\SimpleCache\CacheInterface::class)) {
    interface CacheInterface extends \Psr\SimpleCache\CacheInterface
    {
    }
} else {
    // If it DOES NOT exist, we define our own fallback interface with the same methods.
    interface CacheInterface
    {
        public function clear(): bool;

        public function delete(string $key): bool;

        /** @param iterable<string> $keys */
        public function deleteMultiple(iterable $keys): bool;
        public function get(string $key, mixed $default = null): mixed;

        /**
         * @param iterable<string> $keys
         * @return iterable<string, mixed>
         */
        public function getMultiple(iterable $keys, mixed $default = null): iterable;

        public function has(string $key): bool;

        public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool;

        /** @param iterable<string, mixed> $values */
        public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool;
    }
}
