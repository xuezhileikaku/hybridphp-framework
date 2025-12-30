<?php

namespace HybridPHP\Core\Cache;

use Amp\Future;
use function Amp\async;

/**
 * Multi-level cache implementation (L1: Memory, L2: Redis)
 */
class MultiLevelCache implements CacheInterface
{
    private CacheInterface $l1Cache; // Memory cache
    private CacheInterface $l2Cache; // Redis cache
    private array $config;

    public function __construct(CacheInterface $l1Cache, CacheInterface $l2Cache, array $config = [])
    {
        $this->l1Cache = $l1Cache;
        $this->l2Cache = $l2Cache;
        $this->config = array_merge([
            'l1_ttl_ratio' => 0.1, // L1 cache TTL is 10% of L2 TTL
            'write_through' => true, // Write to both levels simultaneously
            'read_through' => true,  // Read from L2 if L1 miss
        ], $config);
    }

    public function get(string $key, mixed $default = null): Future
    {
        return async(function () use ($key, $default) {
            // Try L1 cache first
            $value = $this->l1Cache->get($key)->await();
            if ($value !== null) {
                return $value;
            }

            // Try L2 cache
            if ($this->config['read_through']) {
                $value = $this->l2Cache->get($key, $default)->await();
                if ($value !== null && $value !== $default) {
                    // Populate L1 cache
                    $this->l1Cache->set($key, $value, $this->getL1Ttl())->await();
                }
                return $value;
            }

            return $default;
        });
    }

    public function set(string $key, mixed $value, ?int $ttl = null): Future
    {
        return async(function () use ($key, $value, $ttl) {
            $promises = [];

            // Set in L1 cache
            $l1Ttl = $this->getL1Ttl($ttl);
            $promises[] = $this->l1Cache->set($key, $value, $l1Ttl);

            // Set in L2 cache
            if ($this->config['write_through']) {
                $promises[] = $this->l2Cache->set($key, $value, $ttl);
            }

            $results = \Amp\Future\await($promises);
            return $results[0] && ($results[1] ?? true);
        });
    }

    public function delete(string $key): Future
    {
        return async(function () use ($key) {
            $promises = [
                $this->l1Cache->delete($key),
                $this->l2Cache->delete($key),
            ];

            $results = \Amp\Future\await($promises);
            return $results[0] || $results[1];
        });
    }

    public function has(string $key): Future
    {
        return async(function () use ($key) {
            $hasL1 = $this->l1Cache->has($key)->await();
            if ($hasL1) {
                return true;
            }

            return $this->l2Cache->has($key)->await();
        });
    }

    public function getMultiple(array $keys, mixed $default = null): Future
    {
        return async(function () use ($keys, $default) {
            // Get from L1 first
            $l1Results = $this->l1Cache->getMultiple($keys, null)->await();
            
            // Find missing keys
            $missingKeys = [];
            foreach ($keys as $key) {
                if ($l1Results[$key] === null) {
                    $missingKeys[] = $key;
                }
            }

            // Get missing keys from L2
            $l2Results = [];
            if (!empty($missingKeys) && $this->config['read_through']) {
                $l2Results = $this->l2Cache->getMultiple($missingKeys, $default)->await();
                
                // Populate L1 with L2 results
                $l1Updates = [];
                foreach ($l2Results as $key => $value) {
                    if ($value !== null && $value !== $default) {
                        $l1Updates[$key] = $value;
                    }
                }
                
                if (!empty($l1Updates)) {
                    $this->l1Cache->setMultiple($l1Updates, $this->getL1Ttl())->await();
                }
            }

            // Merge results
            $results = [];
            foreach ($keys as $key) {
                $results[$key] = $l1Results[$key] ?? $l2Results[$key] ?? $default;
            }

            return $results;
        });
    }

    public function setMultiple(array $values, ?int $ttl = null): Future
    {
        return async(function () use ($values, $ttl) {
            $promises = [];

            // Set in L1
            $l1Ttl = $this->getL1Ttl($ttl);
            $promises[] = $this->l1Cache->setMultiple($values, $l1Ttl);

            // Set in L2
            if ($this->config['write_through']) {
                $promises[] = $this->l2Cache->setMultiple($values, $ttl);
            }

            $results = \Amp\Future\await($promises);
            return $results[0] && ($results[1] ?? true);
        });
    }

    public function deleteMultiple(array $keys): Future
    {
        return async(function () use ($keys) {
            $promises = [
                $this->l1Cache->deleteMultiple($keys),
                $this->l2Cache->deleteMultiple($keys),
            ];

            \Amp\Future\await($promises);
            return true;
        });
    }

    public function clear(): Future
    {
        return async(function () {
            $promises = [
                $this->l1Cache->clear(),
                $this->l2Cache->clear(),
            ];

            \Amp\Future\await($promises);
            return true;
        });
    }

    public function increment(string $key, int $value = 1): Future
    {
        return async(function () use ($key, $value) {
            // Increment in L2 (authoritative)
            $result = $this->l2Cache->increment($key, $value)->await();
            
            // Invalidate L1 cache
            $this->l1Cache->delete($key)->await();
            
            return $result;
        });
    }

    public function decrement(string $key, int $value = 1): Future
    {
        return async(function () use ($key, $value) {
            // Decrement in L2 (authoritative)
            $result = $this->l2Cache->decrement($key, $value)->await();
            
            // Invalidate L1 cache
            $this->l1Cache->delete($key)->await();
            
            return $result;
        });
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): Future
    {
        return async(function () use ($key, $callback, $ttl) {
            // Try L1 first
            $value = $this->l1Cache->get($key)->await();
            if ($value !== null) {
                return $value;
            }

            // Try L2 with remember pattern
            $value = $this->l2Cache->remember($key, $callback, $ttl)->await();
            
            // Populate L1
            if ($value !== null) {
                $this->l1Cache->set($key, $value, $this->getL1Ttl($ttl))->await();
            }
            
            return $value;
        });
    }

    public function getStats(): Future
    {
        return async(function () {
            return [
                'l1' => $this->l1Cache->getStats()->await(),
                'l2' => $this->l2Cache->getStats()->await(),
            ];
        });
    }

    /**
     * Calculate L1 cache TTL based on L2 TTL
     */
    private function getL1Ttl(?int $l2Ttl = null): int
    {
        $baseTtl = $l2Ttl ?? 3600; // Default 1 hour
        return (int) ($baseTtl * $this->config['l1_ttl_ratio']);
    }

    /**
     * Invalidate L1 cache for a key
     */
    public function invalidateL1(string $key): Future
    {
        return $this->l1Cache->delete($key);
    }

    /**
     * Warm up cache with data
     */
    public function warmUp(array $data, ?int $ttl = null): Future
    {
        return $this->setMultiple($data, $ttl);
    }
}