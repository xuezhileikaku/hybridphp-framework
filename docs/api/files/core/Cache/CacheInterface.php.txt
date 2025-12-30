<?php

namespace HybridPHP\Core\Cache;

use Amp\Future;

/**
 * Async Cache Interface
 */
interface CacheInterface
{
    /**
     * Get cache value by key
     */
    public function get(string $key, mixed $default = null): Future;

    /**
     * Set cache value with TTL
     */
    public function set(string $key, mixed $value, ?int $ttl = null): Future;

    /**
     * Delete cache by key
     */
    public function delete(string $key): Future;

    /**
     * Check if cache key exists
     */
    public function has(string $key): Future;

    /**
     * Get multiple cache values
     */
    public function getMultiple(array $keys, mixed $default = null): Future;

    /**
     * Set multiple cache values
     */
    public function setMultiple(array $values, ?int $ttl = null): Future;

    /**
     * Delete multiple cache keys
     */
    public function deleteMultiple(array $keys): Future;

    /**
     * Clear all cache
     */
    public function clear(): Future;

    /**
     * Increment cache value
     */
    public function increment(string $key, int $value = 1): Future;

    /**
     * Decrement cache value
     */
    public function decrement(string $key, int $value = 1): Future;

    /**
     * Get cache with lock (prevent cache stampede)
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): Future;

    /**
     * Get cache statistics
     */
    public function getStats(): Future;
}