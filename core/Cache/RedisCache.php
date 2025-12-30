<?php

namespace HybridPHP\Core\Cache;

use Amp\Future;
use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use function Amp\async;

/**
 * Redis-based async cache implementation
 */
class RedisCache extends AbstractCache
{
    private RedisClient $client;
    private array $nodes = [];
    private ConsistentHash $consistentHash;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        
        $this->initializeNodes($config);
        $this->consistentHash = new ConsistentHash($this->nodes);
    }

    /**
     * Initialize Redis nodes for distributed caching
     */
    private function initializeNodes(array $config): void
    {
        $nodes = $config['nodes'] ?? [
            ['host' => $config['host'] ?? '127.0.0.1', 'port' => $config['port'] ?? 6379]
        ];

        foreach ($nodes as $index => $node) {
            $redisConfig = new RedisConfig(
                $node['host'],
                $node['port'],
                $config['password'] ?? null,
                $config['database'] ?? 0
            );
            
            $this->nodes["node_$index"] = new RedisClient($redisConfig);
        }
    }

    /**
     * Get Redis client for specific key using consistent hashing
     */
    private function getClientForKey(string $key): RedisClient
    {
        $nodeKey = $this->consistentHash->getNode($key);
        return $this->nodes[$nodeKey];
    }

    public function get(string $key, mixed $default = null): Future
    {
        return async(function () use ($key, $default) {
            try {
                $client = $this->getClientForKey($key);
                $value = $client->get($this->buildKey($key))->await();
                
                if ($value === null) {
                    return $default;
                }
                
                return $this->unserialize($value);
            } catch (\Throwable $e) {
                // Log error and return default
                error_log("Redis cache get error: " . $e->getMessage());
                return $default;
            }
        });
    }

    public function set(string $key, mixed $value, ?int $ttl = null): Future
    {
        return async(function () use ($key, $value, $ttl) {
            try {
                $client = $this->getClientForKey($key);
                $serialized = $this->serialize($value);
                $cacheKey = $this->buildKey($key);
                $ttlValue = $this->getTtl($ttl);
                
                $client->setex($cacheKey, $ttlValue, $serialized)->await();
                return true;
            } catch (\Throwable $e) {
                error_log("Redis cache set error: " . $e->getMessage());
                return false;
            }
        });
    }

    public function delete(string $key): Future
    {
        return async(function () use ($key) {
            try {
                $client = $this->getClientForKey($key);
                $result = $client->del($this->buildKey($key))->await();
                return $result > 0;
            } catch (\Throwable $e) {
                error_log("Redis cache delete error: " . $e->getMessage());
                return false;
            }
        });
    }

    public function has(string $key): Future
    {
        return async(function () use ($key) {
            try {
                $client = $this->getClientForKey($key);
                $result = $client->exists($this->buildKey($key))->await();
                return $result > 0;
            } catch (\Throwable $e) {
                error_log("Redis cache has error: " . $e->getMessage());
                return false;
            }
        });
    }

    public function clear(): Future
    {
        return async(function () {
            try {
                $promises = [];
                foreach ($this->nodes as $client) {
                    $promises[] = $client->flushdb();
                }
                \Amp\Future\await($promises);
                return true;
            } catch (\Throwable $e) {
                error_log("Redis cache clear error: " . $e->getMessage());
                return false;
            }
        });
    }

    public function increment(string $key, int $value = 1): Future
    {
        return async(function () use ($key, $value) {
            try {
                $client = $this->getClientForKey($key);
                $result = $client->incrby($this->buildKey($key), $value)->await();
                return $result;
            } catch (\Throwable $e) {
                error_log("Redis cache increment error: " . $e->getMessage());
                return false;
            }
        });
    }

    public function decrement(string $key, int $value = 1): Future
    {
        return async(function () use ($key, $value) {
            try {
                $client = $this->getClientForKey($key);
                $result = $client->decrby($this->buildKey($key), $value)->await();
                return $result;
            } catch (\Throwable $e) {
                error_log("Redis cache decrement error: " . $e->getMessage());
                return false;
            }
        });
    }

    public function getStats(): Future
    {
        return async(function () {
            $stats = [];
            foreach ($this->nodes as $nodeKey => $client) {
                try {
                    $info = $client->info()->await();
                    $stats[$nodeKey] = $this->parseRedisInfo($info);
                } catch (\Throwable $e) {
                    $stats[$nodeKey] = ['error' => $e->getMessage()];
                }
            }
            return $stats;
        });
    }

    /**
     * Parse Redis INFO command output
     */
    private function parseRedisInfo(string $info): array
    {
        $lines = explode("\r\n", $info);
        $stats = [];
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $stats[trim($key)] = trim($value);
            }
        }
        
        return $stats;
    }

    /**
     * Acquire distributed lock using Redis
     */
    protected function acquireLock(string $key, int $ttl): Future
    {
        return async(function () use ($key, $ttl) {
            try {
                $client = $this->getClientForKey($key);
                $result = $client->set($key, '1', 'EX', $ttl, 'NX')->await();
                return $result === 'OK';
            } catch (\Throwable $e) {
                error_log("Redis lock acquire error: " . $e->getMessage());
                return false;
            }
        });
    }

    /**
     * Release distributed lock
     */
    protected function releaseLock(string $key): Future
    {
        return async(function () use ($key) {
            try {
                $client = $this->getClientForKey($key);
                $client->del($key)->await();
                return true;
            } catch (\Throwable $e) {
                error_log("Redis lock release error: " . $e->getMessage());
                return false;
            }
        });
    }

    /**
     * Batch operations for better performance
     */
    public function getMultiple(array $keys, mixed $default = null): Future
    {
        return async(function () use ($keys, $default) {
            // Group keys by node for efficient batch operations
            $keysByNode = [];
            foreach ($keys as $key) {
                $nodeKey = $this->consistentHash->getNode($key);
                $keysByNode[$nodeKey][] = $key;
            }

            $results = [];
            foreach ($keysByNode as $nodeKey => $nodeKeys) {
                try {
                    $client = $this->nodes[$nodeKey];
                    $cacheKeys = array_map([$this, 'buildKey'], $nodeKeys);
                    $values = $client->mget(...$cacheKeys)->await();
                    
                    foreach ($nodeKeys as $index => $key) {
                        $value = $values[$index] ?? null;
                        $results[$key] = $value !== null ? $this->unserialize($value) : $default;
                    }
                } catch (\Throwable $e) {
                    error_log("Redis batch get error: " . $e->getMessage());
                    foreach ($nodeKeys as $key) {
                        $results[$key] = $default;
                    }
                }
            }

            return $results;
        });
    }
}