<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\DataLoader;

use Amp\Future;
use Amp\DeferredFuture;
use function Amp\async;
use function Amp\delay;

/**
 * DataLoader for batching and caching data fetches
 * Solves the N+1 problem in GraphQL resolvers
 */
class DataLoader
{
    /**
     * Batch loading function
     * @var callable(array): Future<array>
     */
    protected $batchLoadFn;

    /**
     * Options
     */
    protected bool $cache;
    protected int $maxBatchSize;
    protected ?string $cacheKeyFn;

    /**
     * Internal state
     */
    protected array $promiseCache = [];
    protected array $queue = [];
    protected bool $scheduled = false;

    /**
     * Create a new DataLoader
     *
     * @param callable $batchLoadFn Function that accepts array of keys and returns Future<array>
     * @param array $options Configuration options
     */
    public function __construct(callable $batchLoadFn, array $options = [])
    {
        $this->batchLoadFn = $batchLoadFn;
        $this->cache = $options['cache'] ?? true;
        $this->maxBatchSize = $options['maxBatchSize'] ?? 100;
        $this->cacheKeyFn = $options['cacheKeyFn'] ?? null;
    }

    /**
     * Load a single value by key
     */
    public function load(mixed $key): Future
    {
        $cacheKey = $this->getCacheKey($key);

        // Check cache
        if ($this->cache && isset($this->promiseCache[$cacheKey])) {
            return $this->promiseCache[$cacheKey];
        }

        // Create deferred future
        $deferred = new DeferredFuture();
        $future = $deferred->getFuture();

        // Add to queue
        $this->queue[] = [
            'key' => $key,
            'deferred' => $deferred,
        ];

        // Cache the future
        if ($this->cache) {
            $this->promiseCache[$cacheKey] = $future;
        }

        // Schedule batch dispatch
        $this->scheduleDispatch();

        return $future;
    }

    /**
     * Load multiple values by keys
     */
    public function loadMany(array $keys): Future
    {
        return async(function () use ($keys) {
            $futures = array_map(fn($key) => $this->load($key), $keys);
            
            $results = [];
            foreach ($futures as $i => $future) {
                try {
                    $results[$i] = $future->await();
                } catch (\Throwable $e) {
                    $results[$i] = $e;
                }
            }
            
            return $results;
        });
    }

    /**
     * Clear a single key from cache
     */
    public function clear(mixed $key): self
    {
        $cacheKey = $this->getCacheKey($key);
        unset($this->promiseCache[$cacheKey]);
        return $this;
    }

    /**
     * Clear all cached values
     */
    public function clearAll(): self
    {
        $this->promiseCache = [];
        return $this;
    }

    /**
     * Prime the cache with a key-value pair
     */
    public function prime(mixed $key, mixed $value): self
    {
        $cacheKey = $this->getCacheKey($key);

        if (!isset($this->promiseCache[$cacheKey])) {
            $deferred = new DeferredFuture();
            
            if ($value instanceof \Throwable) {
                $deferred->error($value);
            } else {
                $deferred->complete($value);
            }
            
            $this->promiseCache[$cacheKey] = $deferred->getFuture();
        }

        return $this;
    }

    /**
     * Get cache key for a value
     */
    protected function getCacheKey(mixed $key): string
    {
        if ($this->cacheKeyFn !== null) {
            return ($this->cacheKeyFn)($key);
        }

        if (is_scalar($key)) {
            return (string) $key;
        }

        return serialize($key);
    }

    /**
     * Schedule batch dispatch
     */
    protected function scheduleDispatch(): void
    {
        if ($this->scheduled) {
            return;
        }

        $this->scheduled = true;

        // Use async to dispatch on next tick
        async(function () {
            // Small delay to allow more items to queue
            delay(0.001);
            $this->dispatchBatch();
        });
    }

    /**
     * Dispatch the current batch
     */
    protected function dispatchBatch(): void
    {
        $this->scheduled = false;

        if (empty($this->queue)) {
            return;
        }

        // Get batch from queue
        $batch = $this->queue;
        $this->queue = [];

        // Split into chunks if needed
        if (count($batch) > $this->maxBatchSize) {
            $chunks = array_chunk($batch, $this->maxBatchSize);
            foreach ($chunks as $chunk) {
                $this->executeBatch($chunk);
            }
        } else {
            $this->executeBatch($batch);
        }
    }

    /**
     * Execute a batch load
     */
    protected function executeBatch(array $batch): void
    {
        $keys = array_map(fn($item) => $item['key'], $batch);

        async(function () use ($batch, $keys) {
            try {
                $batchFuture = ($this->batchLoadFn)($keys);
                $values = $batchFuture instanceof Future ? $batchFuture->await() : $batchFuture;

                if (!is_array($values)) {
                    throw new \RuntimeException('Batch function must return an array');
                }

                if (count($values) !== count($keys)) {
                    throw new \RuntimeException(
                        'Batch function must return array of same length as keys. ' .
                        'Expected ' . count($keys) . ', got ' . count($values)
                    );
                }

                foreach ($batch as $i => $item) {
                    $value = $values[$i];
                    if ($value instanceof \Throwable) {
                        $item['deferred']->error($value);
                    } else {
                        $item['deferred']->complete($value);
                    }
                }
            } catch (\Throwable $e) {
                foreach ($batch as $item) {
                    $item['deferred']->error($e);
                }
            }
        });
    }
}

/**
 * DataLoader factory for creating loaders with common patterns
 */
class DataLoaderFactory
{
    /**
     * Create a DataLoader for loading by ID from a database
     */
    public static function createFromDatabase(
        callable $queryFn,
        string $idField = 'id',
        array $options = []
    ): DataLoader {
        return new DataLoader(
            function (array $ids) use ($queryFn, $idField): Future {
                return async(function () use ($ids, $queryFn, $idField) {
                    $results = $queryFn($ids);
                    
                    if ($results instanceof Future) {
                        $results = $results->await();
                    }

                    // Index by ID
                    $indexed = [];
                    foreach ($results as $row) {
                        $id = is_array($row) ? $row[$idField] : $row->$idField;
                        $indexed[$id] = $row;
                    }

                    // Return in same order as keys
                    return array_map(
                        fn($id) => $indexed[$id] ?? null,
                        $ids
                    );
                });
            },
            $options
        );
    }

    /**
     * Create a DataLoader for loading related items (one-to-many)
     */
    public static function createForRelation(
        callable $queryFn,
        string $foreignKey,
        array $options = []
    ): DataLoader {
        return new DataLoader(
            function (array $parentIds) use ($queryFn, $foreignKey): Future {
                return async(function () use ($parentIds, $queryFn, $foreignKey) {
                    $results = $queryFn($parentIds);
                    
                    if ($results instanceof Future) {
                        $results = $results->await();
                    }

                    // Group by foreign key
                    $grouped = [];
                    foreach ($parentIds as $id) {
                        $grouped[$id] = [];
                    }

                    foreach ($results as $row) {
                        $fk = is_array($row) ? $row[$foreignKey] : $row->$foreignKey;
                        if (isset($grouped[$fk])) {
                            $grouped[$fk][] = $row;
                        }
                    }

                    // Return in same order as keys
                    return array_map(
                        fn($id) => $grouped[$id] ?? [],
                        $parentIds
                    );
                });
            },
            $options
        );
    }
}
