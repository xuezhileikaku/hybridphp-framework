<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database;

use Amp\Future;
use Psr\Log\LoggerInterface;
use function Amp\async;
use function Amp\delay;

/**
 * Database monitoring and statistics collector
 */
class DatabaseMonitor
{
    private DatabaseManager $databaseManager;
    private LoggerInterface $logger;
    private array $config;
    private bool $monitoring = false;
    private array $metrics = [];

    public function __construct(DatabaseManager $databaseManager, LoggerInterface $logger, array $config = [])
    {
        $this->databaseManager = $databaseManager;
        $this->logger = $logger;
        $this->config = array_merge([
            'interval' => 60, // seconds
            'alert_thresholds' => [
                'max_active_connections' => 80, // percentage
                'max_failed_queries' => 10, // percentage
                'max_avg_query_time' => 1000, // milliseconds
            ],
            'retention_period' => 3600, // seconds
        ], $config);
    }

    /**
     * Start monitoring
     */
    public function start(): void
    {
        if ($this->monitoring) {
            return;
        }

        $this->monitoring = true;
        $this->logger->info('Database monitoring started');

        async(function () {
            while ($this->monitoring) {
                try {
                    $this->collectMetrics();
                    $this->checkAlerts();
                    $this->cleanupOldMetrics();
                } catch (\Throwable $e) {
                    $this->logger->error('Error in database monitoring', [
                        'error' => $e->getMessage(),
                    ]);
                }

                delay($this->config['interval']);
            }
        });
    }

    /**
     * Stop monitoring
     */
    public function stop(): void
    {
        $this->monitoring = false;
        $this->logger->info('Database monitoring stopped');
    }

    /**
     * Collect metrics from all connections
     */
    private function collectMetrics(): void
    {
        $timestamp = time();
        $allStats = $this->databaseManager->getAllStats();
        
        foreach ($allStats as $connectionName => $stats) {
            $this->metrics[$connectionName][$timestamp] = [
                'timestamp' => $timestamp,
                'total_connections' => $stats['total_connections'] ?? 0,
                'active_connections' => $stats['active_connections'] ?? 0,
                'idle_connections' => $stats['idle_connections'] ?? 0,
                'failed_connections' => $stats['failed_connections'] ?? 0,
                'total_queries' => $stats['total_queries'] ?? 0,
                'failed_queries' => $stats['failed_queries'] ?? 0,
                'avg_query_time' => $stats['avg_query_time'] ?? 0,
                'last_health_check' => $stats['last_health_check'] ?? null,
            ];
        }

        $this->logger->debug('Database metrics collected', [
            'connections' => array_keys($allStats),
            'timestamp' => $timestamp,
        ]);
    }

    /**
     * Check for alert conditions
     */
    private function checkAlerts(): void
    {
        $thresholds = $this->config['alert_thresholds'];
        
        foreach ($this->metrics as $connectionName => $connectionMetrics) {
            $latestMetrics = end($connectionMetrics);
            if (!$latestMetrics) {
                continue;
            }

            // Check active connections percentage
            $totalConnections = $latestMetrics['total_connections'];
            $activeConnections = $latestMetrics['active_connections'];
            
            if ($totalConnections > 0) {
                $activePercentage = ($activeConnections / $totalConnections) * 100;
                
                if ($activePercentage > $thresholds['max_active_connections']) {
                    $this->logger->warning('High active connection usage detected', [
                        'connection' => $connectionName,
                        'active_percentage' => $activePercentage,
                        'threshold' => $thresholds['max_active_connections'],
                        'active_connections' => $activeConnections,
                        'total_connections' => $totalConnections,
                    ]);
                }
            }

            // Check failed queries percentage
            $totalQueries = $latestMetrics['total_queries'];
            $failedQueries = $latestMetrics['failed_queries'];
            
            if ($totalQueries > 0) {
                $failedPercentage = ($failedQueries / $totalQueries) * 100;
                
                if ($failedPercentage > $thresholds['max_failed_queries']) {
                    $this->logger->warning('High query failure rate detected', [
                        'connection' => $connectionName,
                        'failed_percentage' => $failedPercentage,
                        'threshold' => $thresholds['max_failed_queries'],
                        'failed_queries' => $failedQueries,
                        'total_queries' => $totalQueries,
                    ]);
                }
            }

            // Check average query time
            $avgQueryTime = $latestMetrics['avg_query_time'] * 1000; // Convert to milliseconds
            
            if ($avgQueryTime > $thresholds['max_avg_query_time']) {
                $this->logger->warning('High average query time detected', [
                    'connection' => $connectionName,
                    'avg_query_time_ms' => $avgQueryTime,
                    'threshold_ms' => $thresholds['max_avg_query_time'],
                ]);
            }
        }
    }

    /**
     * Clean up old metrics
     */
    private function cleanupOldMetrics(): void
    {
        $cutoffTime = time() - $this->config['retention_period'];
        
        foreach ($this->metrics as $connectionName => &$connectionMetrics) {
            $connectionMetrics = array_filter(
                $connectionMetrics,
                fn($metrics) => $metrics['timestamp'] > $cutoffTime
            );
        }
    }

    /**
     * Get current metrics
     */
    public function getMetrics(string $connectionName = null): array
    {
        if ($connectionName) {
            return $this->metrics[$connectionName] ?? [];
        }
        
        return $this->metrics;
    }

    /**
     * Get latest metrics for a connection
     */
    public function getLatestMetrics(string $connectionName): ?array
    {
        $connectionMetrics = $this->metrics[$connectionName] ?? [];
        return empty($connectionMetrics) ? null : end($connectionMetrics);
    }

    /**
     * Get metrics summary
     */
    public function getMetricsSummary(): array
    {
        $summary = [];
        
        foreach ($this->metrics as $connectionName => $connectionMetrics) {
            if (empty($connectionMetrics)) {
                continue;
            }

            $latest = end($connectionMetrics);
            $summary[$connectionName] = [
                'status' => $this->getConnectionStatus($connectionName),
                'active_connections' => $latest['active_connections'],
                'total_queries' => $latest['total_queries'],
                'failed_queries' => $latest['failed_queries'],
                'avg_query_time_ms' => round($latest['avg_query_time'] * 1000, 2),
                'last_health_check' => $latest['last_health_check'],
                'uptime' => $this->getConnectionUptime($connectionName),
            ];
        }

        return $summary;
    }

    /**
     * Get connection status
     */
    private function getConnectionStatus(string $connectionName): string
    {
        $latest = $this->getLatestMetrics($connectionName);
        if (!$latest) {
            return 'unknown';
        }

        $lastHealthCheck = $latest['last_health_check'];
        if (!$lastHealthCheck || (time() - $lastHealthCheck) > 300) {
            return 'unhealthy';
        }

        $failedQueries = $latest['failed_queries'];
        $totalQueries = $latest['total_queries'];
        
        if ($totalQueries > 0 && ($failedQueries / $totalQueries) > 0.1) {
            return 'degraded';
        }

        return 'healthy';
    }

    /**
     * Get connection uptime
     */
    private function getConnectionUptime(string $connectionName): int
    {
        $connectionMetrics = $this->metrics[$connectionName] ?? [];
        if (empty($connectionMetrics)) {
            return 0;
        }

        $firstMetric = reset($connectionMetrics);
        return time() - $firstMetric['timestamp'];
    }

    /**
     * Export metrics in Prometheus format
     */
    public function exportPrometheusMetrics(): string
    {
        $output = [];
        
        foreach ($this->metrics as $connectionName => $connectionMetrics) {
            $latest = end($connectionMetrics);
            if (!$latest) {
                continue;
            }

            $labels = "connection=\"$connectionName\"";
            
            $output[] = "# HELP database_active_connections Number of active database connections";
            $output[] = "# TYPE database_active_connections gauge";
            $output[] = "database_active_connections{$labels} {$latest['active_connections']}";
            
            $output[] = "# HELP database_total_queries Total number of database queries";
            $output[] = "# TYPE database_total_queries counter";
            $output[] = "database_total_queries{$labels} {$latest['total_queries']}";
            
            $output[] = "# HELP database_failed_queries Total number of failed database queries";
            $output[] = "# TYPE database_failed_queries counter";
            $output[] = "database_failed_queries{$labels} {$latest['failed_queries']}";
            
            $output[] = "# HELP database_avg_query_time Average query execution time in seconds";
            $output[] = "# TYPE database_avg_query_time gauge";
            $output[] = "database_avg_query_time{$labels} {$latest['avg_query_time']}";
        }

        return implode("\n", $output) . "\n";
    }
}