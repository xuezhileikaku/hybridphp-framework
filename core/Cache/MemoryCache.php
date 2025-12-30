<?php

namespace HybridPHP\Core\Cache;

use Amp\Future;
use function Amp\async;

/**
 * In-memory cache implementation for L1 caching
 */
class MemoryCache extends AbstractCache
{
    private array $storage = [];
    private array $expiry = [];
    private int $maxSize;
    private int $currentSize = 0;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->maxSize = $config['max_size'] ?? 100 * 1024 * 1024; // 100MB default
    }

    public function get(string $key, mixed $default = null): Future
    {
        return async(function () use ($key, $default) {
            $cacheKey = $this->buildKey($key);
            
            if (!isset($this->storage[$cacheKey])) {
                return $default;
            }

            // Check expiry
            if (isset($this->expiry[$cacheKey]) && $this->expiry[$cacheKey] < time()) {
                unset($this->storage[$cacheKey], $this->expiry[$cacheKey]);
                return $default;
            }

            return $this->storage[$cacheKey];
        });
    }

    public function set(string $key, mixed $value, ?int $ttl = null): Future
    {
        return async(function () use ($key, $value, $ttl) {
            $cacheKey = $this->buildKey($key);
            $serialized = $this->serialize($value);
            $size = strlen($serialized);

            // Check if we need to make space
            if ($this->currentSize + $size > $this->maxSize) {
                $this->evictLru($size);
            }

            $this->storage[$cacheKey] = $value;
            $this->currentSize += $size;

            if ($ttl !== null && $ttl > 0) {
                $this->expiry[$cacheKey] = time() + $this->getTtl($ttl);
            }

            return true;
        });
    }

    public function delete(string $key): Future
    {
        return async(function () use ($key) {
            $cacheKey = $this->buildKey($key);
            
            if (isset($this->storage[$cacheKey])) {
                $size = strlen($this->serialize($this->storage[$cacheKey]));
                $this->currentSize -= $size;
                unset($this->storage[$cacheKey], $this->expiry[$cacheKey]);
                return true;
            }

            return false;
        });
    }

    public function has(string $key): Future
    {
        return async(function () use ($key) {
            $cacheKey = $this->buildKey($key);
            
            if (!isset($this->storage[$cacheKey])) {
                return false;
            }

            // Check expiry
            if (isset($this->expiry[$cacheKey]) && $this->expiry[$cacheKey] < time()) {
                unset($this->storage[$cacheKey], $this->expiry[$cacheKey]);
                return false;
            }

            return true;
        });
    }

    public function clear(): Future
    {
        return async(function () {
            $this->storage = [];
            $this->expiry = [];
            $this->currentSize = 0;
            return true;
        });
    }

    public function increment(string $key, int $value = 1): Future
    {
        return async(function () use ($key, $value) {
            $current = $this->get($key, 0)->await();
            $newValue = (int) $current + $value;
            $this->set($key, $newValue)->await();
            return $newValue;
        });
    }

    public function decrement(string $key, int $value = 1): Future
    {
        return async(function () use ($key, $value) {
            $current = $this->get($key, 0)->await();
            $newValue = (int) $current - $value;
            $this->set($key, $newValue)->await();
            return $newValue;
        });
    }

    public function getStats(): Future
    {
        return async(function () {
            $this->cleanupExpired();
            
            return [
                'total_keys' => count($this->storage),
                'memory_usage' => $this->currentSize,
                'max_memory' => $this->maxSize,
                'memory_usage_percent' => ($this->currentSize / $this->maxSize) * 100,
                'expired_keys' => count($this->expiry),
            ];
        });
    }

    protected function acquireLock(string $key, int $ttl): Future
    {
        return async(function () use ($key, $ttl) {
            if (isset($this->storage[$key])) {
                return false; // Lock already exists
            }

            $this->storage[$key] = true;
            $this->expiry[$key] = time() + $ttl;
            return true;
        });
    }

    protected function releaseLock(string $key): Future
    {
        return async(function () use ($key) {
            unset($this->storage[$key], $this->expiry[$key]);
            return true;
        });
    }

    /**
     * Evict least recently used items to make space
     */
    private function evictLru(int $neededSize): void
    {
        $evicted = 0;
        $keys = array_keys($this->storage);
        
        // Simple LRU: remove oldest entries first
        foreach ($keys as $key) {
            if ($this->currentSize + $neededSize <= $this->maxSize) {
                break;
            }

            $size = strlen($this->serialize($this->storage[$key]));
            unset($this->storage[$key], $this->expiry[$key]);
            $this->currentSize -= $size;
            $evicted++;
        }
    }

    /**
     * Clean up expired entries
     */
    private function cleanupExpired(): void
    {
        $now = time();
        foreach ($this->expiry as $key => $expireTime) {
            if ($expireTime < $now) {
                $size = strlen($this->serialize($this->storage[$key]));
                $this->currentSize -= $size;
                unset($this->storage[$key], $this->expiry[$key]);
            }
        }
    }
}