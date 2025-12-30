<?php

declare(strict_types=1);

namespace HybridPHP\Core\Health\Checks;

use HybridPHP\Core\Health\AbstractHealthCheck;
use HybridPHP\Core\Health\HealthCheckResult;
use HybridPHP\Core\Cache\CacheInterface;
use Amp\Future;
use Psr\Log\LoggerInterface;
use function Amp\async;

/**
 * Cache health check
 */
class CacheHealthCheck extends AbstractHealthCheck
{
    private CacheInterface $cache;

    public function __construct(
        CacheInterface $cache,
        ?LoggerInterface $logger = null,
        int $timeout = 3,
        bool $critical = false
    ) {
        parent::__construct('cache', $timeout, $critical, $logger);
        $this->cache = $cache;
    }

    protected function performCheck(): Future
    {
        return async(function () {
            try {
                $testKey = 'health_check_' . uniqid();
                $testValue = 'health_check_value_' . time();

                // Test write operation
                $writeResult = $this->cache->set($testKey, $testValue, 60)->await();
                if (!$writeResult) {
                    return HealthCheckResult::unhealthy(
                        $this->name,
                        'Cache write operation failed'
                    );
                }

                // Test read operation
                $readValue = $this->cache->get($testKey)->await();
                if ($readValue !== $testValue) {
                    return HealthCheckResult::unhealthy(
                        $this->name,
                        'Cache read operation failed or returned incorrect value'
                    );
                }

                // Test delete operation
                $deleteResult = $this->cache->delete($testKey)->await();
                if (!$deleteResult) {
                    return HealthCheckResult::warning(
                        $this->name,
                        'Cache delete operation failed'
                    );
                }

                // Get cache statistics if available
                $stats = [];
                if (method_exists($this->cache, 'getStats')) {
                    $stats = $this->cache->getStats();
                }

                // Check memory usage if available
                if (isset($stats['memory_usage']) && isset($stats['memory_limit'])) {
                    $memoryUsagePercent = ($stats['memory_usage'] / $stats['memory_limit']) * 100;
                    if ($memoryUsagePercent > 90) {
                        return HealthCheckResult::warning(
                            $this->name,
                            'Cache memory usage is high',
                            array_merge($stats, ['memory_usage_percent' => $memoryUsagePercent])
                        );
                    }
                }

                return HealthCheckResult::healthy(
                    $this->name,
                    'Cache is healthy',
                    $stats
                );

            } catch (\Throwable $e) {
                return HealthCheckResult::unhealthy(
                    $this->name,
                    'Cache health check failed: ' . $e->getMessage(),
                    [],
                    0.0,
                    $e
                );
            }
        });
    }
}