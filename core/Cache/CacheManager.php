<?php

namespace HybridPHP\Core\Cache;

use Amp\Future;
use HybridPHP\Core\ConfigManager;
use function Amp\async;

/**
 * Cache Manager with anti-patterns protection
 */
class CacheManager
{
    private array $stores = [];
    private array $config;
    private string $defaultStore;

    public function __construct(ConfigManager $configManager)
    {
        $this->config = $configManager->get('cache', []);
        $this->defaultStore = $this->config['default'] ?? 'redis';
    }

    /**
     * Get cache store instance
     */
    public function store(string $name = null): CacheInterface
    {
        $name = $name ?? $this->defaultStore;

        if (!isset($this->stores[$name])) {
            $this->stores[$name] = $this->createStore($name);
        }

        return $this->stores[$name];
    }

    /**
     * Create cache store instance
     */
    private function createStore(string $name): CacheInterface
    {
        $config = $this->config['stores'][$name] ?? [];
        
        return match ($config['driver'] ?? 'redis') {
            'redis' => new RedisCache($config),
            'memory' => new MemoryCache($config),
            'multilevel' => $this->createMultiLevelCache($config),
            'file' => new FileCache($config),
            default => throw new \InvalidArgumentException("Unsupported cache driver: {$config['driver']}")
        };
    }

    /**
     * Create multi-level cache
     */
    private function createMultiLevelCache(array $config): MultiLevelCache
    {
        $l1Config = $config['l1'] ?? ['driver' => 'memory'];
        $l2Config = $config['l2'] ?? ['driver' => 'redis'];

        $l1Cache = $this->createStore('memory');
        $l2Cache = $this->createStore('redis');

        return new MultiLevelCache($l1Cache, $l2Cache, $config);
    }

    /**
     * Cache with anti-stampede protection
     */
    public function remember(string $key, callable $callback, ?int $ttl = null, string $store = null): Future
    {
        return $this->store($store)->remember($key, $callback, $ttl);
    }

    /**
     * Cache with anti-penetration protection
     */
    public function rememberWithNullProtection(string $key, callable $callback, ?int $ttl = null, string $store = null): Future
    {
        return async(function () use ($key, $callback, $ttl, $store) {
            $cache = $this->store($store);
            $nullKey = $key . ':null';
            
            // Check if we have a null marker
            $hasNull = $cache->has($nullKey)->await();
            if ($hasNull) {
                return null;
            }

            // Try to get the actual value
            $value = $cache->get($key)->await();
            if ($value !== null) {
                return $value;
            }

            // Execute callback
            $value = $callback()->await();
            
            if ($value === null) {
                // Cache null result with shorter TTL to prevent penetration
                $nullTtl = min($ttl ?? 300, 300); // Max 5 minutes for null values
                $cache->set($nullKey, true, $nullTtl)->await();
            } else {
                $cache->set($key, $value, $ttl)->await();
            }

            return $value;
        });
    }

    /**
     * Batch cache operations to prevent avalanche
     */
    public function batchRemember(array $keys, callable $callback, ?int $ttl = null, string $store = null): Future
    {
        return async(function () use ($keys, $callback, $ttl, $store) {
            $cache = $this->store($store);
            
            // Get existing values
            $existing = $cache->getMultiple($keys)->await();
            
            // Find missing keys
            $missing = [];
            foreach ($keys as $key) {
                if ($existing[$key] === null) {
                    $missing[] = $key;
                }
            }

            // Fetch missing values
            if (!empty($missing)) {
                $newValues = $callback($missing)->await();
                
                // Cache new values
                if (!empty($newValues)) {
                    $cache->setMultiple($newValues, $ttl)->await();
                    $existing = array_merge($existing, $newValues);
                }
            }

            return $existing;
        });
    }

    /**
     * Warm up cache with data
     */
    public function warmUp(array $data, ?int $ttl = null, string $store = null): Future
    {
        return $this->store($store)->setMultiple($data, $ttl);
    }

    /**
     * Cache invalidation with tags
     */
    public function invalidateByTags(array $tags, string $store = null): Future
    {
        return async(function () use ($tags, $store) {
            $cache = $this->store($store);

            foreach ($tags as $tag) {
                // Get keys associated with this tag
                $tagKey = "tag:{$tag}";
                $keys = $cache->get($tagKey, [])->await();
                
                if (!empty($keys)) {
                    $cache->deleteMultiple($keys)->await();
                    $cache->delete($tagKey)->await();
                }
            }

            return true;
        });
    }

    /**
     * Set cache with tags
     */
    public function setWithTags(string $key, mixed $value, array $tags = [], ?int $ttl = null, string $store = null): Future
    {
        return async(function () use ($key, $value, $tags, $ttl, $store) {
            $cache = $this->store($store);
            
            // Set the main value
            $cache->set($key, $value, $ttl)->await();
            
            // Associate with tags
            foreach ($tags as $tag) {
                $tagKey = "tag:{$tag}";
                $keys = $cache->get($tagKey, [])->await();
                $keys[] = $key;
                $keys = array_unique($keys);
                $cache->set($tagKey, $keys, $ttl)->await();
            }

            return true;
        });
    }

    /**
     * Get cache statistics
     */
    public function getStats(string $store = null): Future
    {
        return $this->store($store)->getStats();
    }

    /**
     * Health check for cache stores
     */
    public function healthCheck(): Future
    {
        return async(function () {
            $results = [];
            
            foreach ($this->config['stores'] as $name => $config) {
                try {
                    $store = $this->store($name);
                    $testKey = 'health_check_' . time();
                    
                    // Test write
                    $store->set($testKey, 'test', 10)->await();
                    
                    // Test read
                    $value = $store->get($testKey)->await();
                    
                    // Test delete
                    $store->delete($testKey)->await();
                    
                    $results[$name] = [
                        'status' => 'healthy',
                        'response_time' => microtime(true),
                    ];
                } catch (\Throwable $e) {
                    $results[$name] = [
                        'status' => 'unhealthy',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return $results;
        });
    }

    /**
     * Clear all caches
     */
    public function clearAll(): Future
    {
        return async(function () {
            foreach (array_keys($this->config['stores']) as $name) {
                $this->store($name)->clear()->await();
            }
            return true;
        });
    }
}