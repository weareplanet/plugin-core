<?php

namespace WeArePlanet\PluginCore\Examples\Common;

use WeArePlanet\PluginCore\SharedKernel\CacheInterface;

// Concept documentation: See docs/examples/Common/README.md and docs/1-Getting-Started/GlobalData.md

/**
 * A simple file-based cache, for demonstration purposes only.
 *
 * Each entry is its own file under a local cache directory, holding a
 * serialized value plus its expiry timestamp. File-based, rather than a
 * plain in-memory array, on purpose: caching only pays off across separate
 * requests, and a PHP CLI script is a fresh process every run — an in-memory
 * cache would reset on every invocation and never demonstrate anything. This
 * is what lets running an example script twice show the second run skipping
 * the API call, the same way two separate admin-page loads would in a real
 * shop.
 *
 * A real plugin should point this at whatever cache the host application
 * already has (Redis, APCu, a framework's cache pool) instead — implementing
 * this same {@see CacheInterface}. See "Caching label descriptors and their
 * groups" in docs/1-Getting-Started/GlobalData.md.
 */
class SimpleCache implements CacheInterface
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? getcwd() . '/.cache';

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->readEntry($key)['value'] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $expiresAt = match (true) {
            $ttl === null => null,
            $ttl instanceof \DateInterval => (new \DateTimeImmutable())->add($ttl)->getTimestamp(),
            default => time() + $ttl,
        };

        file_put_contents($this->pathFor($key), serialize(['value' => $value, 'expiresAt' => $expiresAt]));

        return true;
    }

    public function delete(string $key): bool
    {
        $path = $this->pathFor($key);

        if (file_exists($path)) {
            unlink($path);
        }

        return true;
    }

    public function clear(): bool
    {
        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            unlink($file);
        }

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string)$key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->readEntry($key) !== null;
    }

    /**
     * @return array{value: mixed, expiresAt: int|null}|null
     */
    private function readEntry(string $key): ?array
    {
        $path = $this->pathFor($key);

        if (!file_exists($path)) {
            return null;
        }

        $entry = unserialize(file_get_contents($path));

        if ($entry['expiresAt'] !== null && $entry['expiresAt'] < time()) {
            unlink($path);

            return null;
        }

        return $entry;
    }

    private function pathFor(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.cache';
    }
}
