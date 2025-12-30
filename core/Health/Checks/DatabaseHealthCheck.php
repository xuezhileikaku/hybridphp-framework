<?php

declare(strict_types=1);

namespace HybridPHP\Core\Health\Checks;

use HybridPHP\Core\Health\AbstractHealthCheck;
use HybridPHP\Core\Health\HealthCheckResult;
use HybridPHP\Core\Database\DatabaseInterface;
use Amp\Future;
use Psr\Log\LoggerInterface;
use function Amp\async;

/**
 * Database health check
 */
class DatabaseHealthCheck extends AbstractHealthCheck
{
    private DatabaseInterface $database;

    public function __construct(
        DatabaseInterface $database,
        ?LoggerInterface $logger = null,
        int $timeout = 5,
        bool $critical = true
    ) {
        parent::__construct('database', $timeout, $critical, $logger);
        $this->database = $database;
    }

    protected function performCheck(): Future
    {
        return async(function () {
            try {
                // Test basic connectivity
                $result = $this->database->query('SELECT 1 as health_check')->await();

                // Get database statistics
                $stats = $this->database->getStats();

                // Check connection pool health
                $poolHealth = $this->database->healthCheck()->await();

                if (!$poolHealth) {
                    return HealthCheckResult::unhealthy(
                        $this->name,
                        'Database connection pool is unhealthy',
                        $stats
                    );
                }

                // Check for high error rate
                $errorRate = $stats['failed_queries'] / max($stats['total_queries'], 1);
                if ($errorRate > 0.1) {
                    return HealthCheckResult::warning(
                        $this->name,
                        'High database error rate detected',
                        array_merge($stats, ['error_rate' => $errorRate])
                    );
                }

                // Check for slow queries
                if ($stats['avg_query_time'] > 1.0) {
                    return HealthCheckResult::warning(
                        $this->name,
                        'Slow database queries detected',
                        $stats
                    );
                }

                return HealthCheckResult::healthy(
                    $this->name,
                    'Database is healthy',
                    $stats
                );

            } catch (\Throwable $e) {
                return HealthCheckResult::unhealthy(
                    $this->name,
                    'Database connection failed: ' . $e->getMessage(),
                    [],
                    0.0,
                    $e
                );
            }
        });
    }
}