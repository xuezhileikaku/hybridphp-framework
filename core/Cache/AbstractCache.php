<?php

namespace HybridPHP\Core\Cache;

use Amp\Future;
use function Amp\async;
use function Amp\delay;

/**
 * Abstract Cache Implementation with common functionality
 */
abstract class AbstractCache implements CacheInterface
{
    protected array $config;
    protected string $prefix;
    protected int $defaultTtl;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->prefix = $config['prefix'] ?? 'hybridphp_cache_';
        $this->defaultTtl = $config['default_ttl'] ?? 3600;
    }

    /**
     * Generate cache key with prefix
     */
    protected function buildKey(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * Serialize value for storage
     */
    protected function serialize(mixed $value): string
    {
        return serialize($value);
    }

    /**
     * Unserialize value from storage
     */
    protected function unserialize(string $value): mixed
    {
        return unserialize($value);
    }

    /**
     * Get TTL value
     */
    protected function getTtl(?int $ttl = null): int
    {
        return $ttl ?? $this->defaultTtl;
    }

    /**
     * Remember pattern with lock to prevent cache stampede
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): Future
    {
        return async(function () use ($key, $callback, $ttl) {
            $lockKey = $this->buildKey($key . ':lock');
            $value = $this->get($key)->await();
            
            if ($value !== null) {
                return $value;
            }

            // Try to acquire lock
            $lockAcquired = $this->acquireLock($lockKey, 30)->await(); // 30 seconds lock
            
            if (!$lockAcquired) {
                // Wait and try to get value again
                delay(0.1); // 100ms
                $value = $this->get($key)->await();
                if ($value !== null) {
                    return $value;
                }
                // Fallback to callback without lock
            }

            try {
                $value = $callback()->await();
                $this->set($key, $value, $ttl)->await();
                return $value;
            } finally {
                if ($lockAcquired) {
                    $this->releaseLock($lockKey)->await();
                }
            }
        });
    }

    /**
     * Acquire distributed lock
     */
    abstract protected function acquireLock(string $key, int $ttl): Future;

    /**
     * Release distributed lock
     */
    abstract protected function releaseLock(string $key): Future;

    /**
     * Get multiple values with fallback
     */
    public function getMultiple(array $keys, mixed $default = null): Future
    {
        return async(function () use ($keys, $default) {
            $results = [];
            foreach ($keys as $key) {
                $results[$key] = $this->get($key, $default)->await();
            }
            return $results;
        });
    }

    /**
     * Set multiple values
     */
    public function setMultiple(array $values, ?int $ttl = null): Future
    {
        return async(function () use ($values, $ttl) {
            foreach ($values as $key => $value) {
                $this->set($key, $value, $ttl)->await();
            }
            return true;
        });
    }

    /**
     * Delete multiple keys
     */
    public function deleteMultiple(array $keys): Future
    {
        return async(function () use ($keys) {
            foreach ($keys as $key) {
                $this->delete($key)->await();
            }
            return true;
        });
    }
}