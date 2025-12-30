<?php

namespace HybridPHP\Core\Cache;

use Amp\Future;
use function Amp\async;

/**
 * Cacheable trait for models and services
 */
trait CacheableTrait
{
    protected ?CacheManager $cacheManager = null;
    protected string $cacheStore = 'multilevel';
    protected int $cacheTtl = 3600;
    protected array $cacheTags = [];

    protected function getCacheManager(): CacheManager
    {
        if ($this->cacheManager === null) {
            throw new \RuntimeException('Cache manager not set');
        }
        return $this->cacheManager;
    }

    public function setCacheManager(CacheManager $cacheManager): void
    {
        $this->cacheManager = $cacheManager;
    }

    protected function cache(string $key, callable $callback, ?int $ttl = null): Future
    {
        return async(function () use ($key, $callback, $ttl) {
            $cacheKey = $this->buildCacheKey($key);
            $cache = $this->getCacheManager();
            
            return $cache->remember(
                $cacheKey,
                $callback,
                $ttl ?? $this->cacheTtl,
                $this->cacheStore
            )->await();
        });
    }

    protected function cacheWithNullProtection(string $key, callable $callback, ?int $ttl = null): Future
    {
        return async(function () use ($key, $callback, $ttl) {
            $cacheKey = $this->buildCacheKey($key);
            $cache = $this->getCacheManager();
            
            return $cache->rememberWithNullProtection(
                $cacheKey,
                $callback,
                $ttl ?? $this->cacheTtl,
                $this->cacheStore
            )->await();
        });
    }

    protected function cacheWithTags(string $key, callable $callback, array $tags = [], ?int $ttl = null): Future
    {
        return async(function () use ($key, $callback, $tags, $ttl) {
            $cacheKey = $this->buildCacheKey($key);
            $cache = $this->getCacheManager();
            
            $value = $callback()->await();
            
            $cache->setWithTags(
                $cacheKey,
                $value,
                array_merge($this->cacheTags, $tags),
                $ttl ?? $this->cacheTtl,
                $this->cacheStore
            )->await();
            
            return $value;
        });
    }

    protected function getCached(string $key, mixed $default = null): Future
    {
        return async(function () use ($key, $default) {
            $cacheKey = $this->buildCacheKey($key);
            $cache = $this->getCacheManager()->store($this->cacheStore);
            
            return $cache->get($cacheKey, $default)->await();
        });
    }

    protected function setCached(string $key, mixed $value, ?int $ttl = null): Future
    {
        return async(function () use ($key, $value, $ttl) {
            $cacheKey = $this->buildCacheKey($key);
            $cache = $this->getCacheManager()->store($this->cacheStore);
            
            return $cache->set($cacheKey, $value, $ttl ?? $this->cacheTtl)->await();
        });
    }

    protected function deleteCached(string $key): Future
    {
        return async(function () use ($key) {
            $cacheKey = $this->buildCacheKey($key);
            $cache = $this->getCacheManager()->store($this->cacheStore);
            
            return $cache->delete($cacheKey)->await();
        });
    }

    protected function invalidateByTags(array $tags): Future
    {
        return $this->getCacheManager()->invalidateByTags($tags, $this->cacheStore);
    }

    protected function buildCacheKey(string $key): string
    {
        $class = static::class;
        $className = substr($class, strrpos($class, '\\') + 1);
        return strtolower($className) . ':' . $key;
    }

    protected function batchCache(array $keys, callable $callback, ?int $ttl = null): Future
    {
        return async(function () use ($keys, $callback, $ttl) {
            $cacheKeys = array_map([$this, 'buildCacheKey'], $keys);
            $cache = $this->getCacheManager();
            
            return $cache->batchRemember(
                $cacheKeys,
                $callback,
                $ttl ?? $this->cacheTtl,
                $this->cacheStore
            )->await();
        });
    }

    protected function warmUpCache(array $data, ?int $ttl = null): Future
    {
        return async(function () use ($data, $ttl) {
            $cacheData = [];
            foreach ($data as $key => $value) {
                $cacheData[$this->buildCacheKey($key)] = $value;
            }
            
            $cache = $this->getCacheManager();
            return $cache->warmUp($cacheData, $ttl ?? $this->cacheTtl, $this->cacheStore)->await();
        });
    }
}
