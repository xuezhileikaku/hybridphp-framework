<?php

declare(strict_types=1);

namespace HybridPHP\Core\Debug;

use Amp\Future;
use Psr\Log\LoggerInterface;
use function Amp\async;
use function Amp\delay;

/**
 * Coroutine debugging and monitoring tool
 */
class CoroutineDebugger
{
    private array $coroutines = [];
    private array $coroutineStacks = [];
    private array $coroutineMetrics = [];
    private ?LoggerInterface $logger;
    private bool $enabled = true;
    private bool $collectStacks = true;
    private int $maxStackDepth = 20;
    private float $slowCoroutineThreshold = 1.0; // seconds

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Register a coroutine for debugging
     */
    public function registerCoroutine(string $id, string $name, callable $coroutine, array $context = []): Future
    {
        if (!$this->enabled) {
            return async($coroutine);
        }

        $this->coroutines[$id] = [
            'id' => $id,
            'name' => $name,
            'status' => 'created',
            'created_at' => microtime(true),
            'context' => $context,
            'memory_start' => memory_get_usage(true),
        ];

        if ($this->collectStacks) {
            $this->coroutineStacks[$id] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->maxStackDepth);
        }

        return async(function () use ($id, $name, $coroutine) {
            try {
                $this->updateCoroutineStatus($id, 'running');
                
                $startTime = microtime(true);
                $result = $coroutine();
                $duration = microtime(true) - $startTime;
                
                $this->updateCoroutineStatus($id, 'completed', [
                    'duration' => $duration,
                    'memory_end' => memory_get_usage(true),
                ]);

                // Check for slow coroutines
                if ($duration > $this->slowCoroutineThreshold) {
                    $this->logSlowCoroutine($id, $duration);
                }

                return $result;
            } catch (\Throwable $e) {
                $this->updateCoroutineStatus($id, 'failed', [
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'memory_end' => memory_get_usage(true),
                ]);
                
                if ($this->logger) {
                    $this->logger->error("Coroutine {$name} failed", [
                        'coroutine_id' => $id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
                
                throw $e;
            }
        });
    }

    /**
     * Update coroutine status
     */
    public function updateCoroutineStatus(string $id, string $status, array $data = []): void
    {
        if (!isset($this->coroutines[$id])) {
            return;
        }

        $this->coroutines[$id]['status'] = $status;
        $this->coroutines[$id]['updated_at'] = microtime(true);
        
        foreach ($data as $key => $value) {
            $this->coroutines[$id][$key] = $value;
        }

        // Record metrics
        if (!isset($this->coroutineMetrics[$id])) {
            $this->coroutineMetrics[$id] = [
                'status_changes' => [],
                'performance' => [],
            ];
        }

        $this->coroutineMetrics[$id]['status_changes'][] = [
            'status' => $status,
            'timestamp' => microtime(true),
            'data' => $data,
        ];

        // Calculate performance metrics
        if ($status === 'completed' || $status === 'failed') {
            $coroutine = $this->coroutines[$id];
            $this->coroutineMetrics[$id]['performance'] = [
                'total_duration' => $coroutine['updated_at'] - $coroutine['created_at'],
                'execution_duration' => $coroutine['duration'] ?? 0,
                'memory_used' => ($coroutine['memory_end'] ?? 0) - $coroutine['memory_start'],
                'status_change_count' => count($this->coroutineMetrics[$id]['status_changes']),
            ];
        }
    }

    /**
     * Get coroutine information
     */
    public function getCoroutine(string $id): ?array
    {
        return $this->coroutines[$id] ?? null;
    }

    /**
     * Get all coroutines
     */
    public function getAllCoroutines(): array
    {
        return $this->coroutines;
    }

    /**
     * Get active coroutines
     */
    public function getActiveCoroutines(): array
    {
        return array_filter($this->coroutines, function ($coroutine) {
            return !in_array($coroutine['status'], ['completed', 'failed']);
        });
    }

    /**
     * Get completed coroutines
     */
    public function getCompletedCoroutines(): array
    {
        return array_filter($this->coroutines, function ($coroutine) {
            return $coroutine['status'] === 'completed';
        });
    }

    /**
     * Get failed coroutines
     */
    public function getFailedCoroutines(): array
    {
        return array_filter($this->coroutines, function ($coroutine) {
            return $coroutine['status'] === 'failed';
        });
    }

    /**
     * Get slow coroutines
     */
    public function getSlowCoroutines(): array
    {
        $slow = [];
        
        foreach ($this->coroutines as $coroutine) {
            if (isset($coroutine['duration']) && $coroutine['duration'] > $this->slowCoroutineThreshold) {
                $slow[] = $coroutine;
            }
        }

        // Sort by duration descending
        usort($slow, function ($a, $b) {
            return ($b['duration'] ?? 0) <=> ($a['duration'] ?? 0);
        });

        return $slow;
    }

    /**
     * Get coroutine statistics
     */
    public function getStatistics(): array
    {
        $total = count($this->coroutines);
        $active = count($this->getActiveCoroutines());
        $completed = count($this->getCompletedCoroutines());
        $failed = count($this->getFailedCoroutines());
        $slow = count($this->getSlowCoroutines());

        $totalDuration = 0;
        $totalMemory = 0;
        $completedCount = 0;

        foreach ($this->coroutines as $coroutine) {
            if (isset($coroutine['duration'])) {
                $totalDuration += $coroutine['duration'];
                $completedCount++;
            }
            
            if (isset($coroutine['memory_end'])) {
                $totalMemory += $coroutine['memory_end'] - $coroutine['memory_start'];
            }
        }

        return [
            'total_coroutines' => $total,
            'active_coroutines' => $active,
            'completed_coroutines' => $completed,
            'failed_coroutines' => $failed,
            'slow_coroutines' => $slow,
            'success_rate' => $total > 0 ? ($completed / $total) * 100 : 0,
            'failure_rate' => $total > 0 ? ($failed / $total) * 100 : 0,
            'average_duration' => $completedCount > 0 ? $totalDuration / $completedCount : 0,
            'total_memory_used' => $totalMemory,
            'slow_threshold' => $this->slowCoroutineThreshold,
        ];
    }

    /**
     * Get coroutine stack trace
     */
    public function getCoroutineStack(string $id): ?array
    {
        return $this->coroutineStacks[$id] ?? null;
    }

    /**
     * Get detailed coroutine report
     */
    public function getDetailedReport(): array
    {
        return [
            'statistics' => $this->getStatistics(),
            'active_coroutines' => $this->getActiveCoroutines(),
            'slow_coroutines' => $this->getSlowCoroutines(),
            'failed_coroutines' => $this->getFailedCoroutines(),
            'metrics' => $this->coroutineMetrics,
            'stacks' => $this->collectStacks ? $this->coroutineStacks : [],
        ];
    }

    /**
     * Start monitoring coroutines
     */
    public function startMonitoring(int $intervalSeconds = 5): Future
    {
        return async(function () use ($intervalSeconds) {
            while ($this->enabled) {
                try {
                    $this->checkForStuckCoroutines();
                    $this->cleanupOldCoroutines();
                    $this->logStatistics();
                } catch (\Throwable $e) {
                    if ($this->logger) {
                        $this->logger->error('Coroutine monitoring failed', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                delay($intervalSeconds);
            }
        });
    }

    /**
     * Stop monitoring
     */
    public function stopMonitoring(): void
    {
        $this->enabled = false;
    }

    /**
     * Check for stuck coroutines
     */
    private function checkForStuckCoroutines(): void
    {
        $stuckThreshold = 30.0; // 30 seconds
        $currentTime = microtime(true);

        foreach ($this->getActiveCoroutines() as $coroutine) {
            $runningTime = $currentTime - $coroutine['created_at'];
            
            if ($runningTime > $stuckThreshold) {
                if ($this->logger) {
                    $this->logger->warning('Potentially stuck coroutine detected', [
                        'coroutine_id' => $coroutine['id'],
                        'name' => $coroutine['name'],
                        'running_time' => $runningTime,
                        'status' => $coroutine['status'],
                    ]);
                }
            }
        }
    }

    /**
     * Clean up old coroutines to prevent memory leaks
     */
    private function cleanupOldCoroutines(): void
    {
        $maxAge = 3600; // 1 hour
        $currentTime = microtime(true);
        $cleaned = 0;

        foreach ($this->coroutines as $id => $coroutine) {
            if (in_array($coroutine['status'], ['completed', 'failed'])) {
                $age = $currentTime - $coroutine['created_at'];
                
                if ($age > $maxAge) {
                    unset($this->coroutines[$id]);
                    unset($this->coroutineStacks[$id]);
                    unset($this->coroutineMetrics[$id]);
                    $cleaned++;
                }
            }
        }

        if ($cleaned > 0 && $this->logger) {
            $this->logger->debug("Cleaned up {$cleaned} old coroutines");
        }
    }

    /**
     * Log coroutine statistics
     */
    private function logStatistics(): void
    {
        if (!$this->logger) {
            return;
        }

        $stats = $this->getStatistics();
        
        $this->logger->info('Coroutine statistics', [
            'total' => $stats['total_coroutines'],
            'active' => $stats['active_coroutines'],
            'completed' => $stats['completed_coroutines'],
            'failed' => $stats['failed_coroutines'],
            'slow' => $stats['slow_coroutines'],
            'success_rate' => round($stats['success_rate'], 2),
            'avg_duration' => round($stats['average_duration'], 4),
        ]);
    }

    /**
     * Log slow coroutine
     */
    private function logSlowCoroutine(string $id, float $duration): void
    {
        if (!$this->logger) {
            return;
        }

        $coroutine = $this->coroutines[$id];
        
        $this->logger->warning('Slow coroutine detected', [
            'coroutine_id' => $id,
            'name' => $coroutine['name'],
            'duration' => $duration,
            'threshold' => $this->slowCoroutineThreshold,
            'context' => $coroutine['context'],
        ]);
    }

    /**
     * Export coroutine data for analysis
     */
    public function exportData(string $format = 'json'): string
    {
        $data = $this->getDetailedReport();

        switch ($format) {
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT);
            
            case 'csv':
                return $this->exportToCsv($data);
            
            default:
                throw new \InvalidArgumentException("Unsupported format: {$format}");
        }
    }

    /**
     * Export to CSV format
     */
    private function exportToCsv(array $data): string
    {
        $csv = "ID,Name,Status,Duration,Memory Used,Created At,Context\n";
        
        foreach ($this->coroutines as $coroutine) {
            $csv .= sprintf(
                "%s,%s,%s,%.4f,%d,%.3f,%s\n",
                $coroutine['id'],
                $coroutine['name'],
                $coroutine['status'],
                $coroutine['duration'] ?? 0,
                ($coroutine['memory_end'] ?? $coroutine['memory_start']) - $coroutine['memory_start'],
                $coroutine['created_at'],
                json_encode($coroutine['context'])
            );
        }
        
        return $csv;
    }

    /**
     * Set slow coroutine threshold
     */
    public function setSlowThreshold(float $seconds): void
    {
        $this->slowCoroutineThreshold = $seconds;
    }

    /**
     * Enable/disable stack collection
     */
    public function setStackCollection(bool $collect, int $maxDepth = 20): void
    {
        $this->collectStacks = $collect;
        $this->maxStackDepth = $maxDepth;
    }

    /**
     * Enable/disable debugging
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Clear all debugging data
     */
    public function clear(): void
    {
        $this->coroutines = [];
        $this->coroutineStacks = [];
        $this->coroutineMetrics = [];
    }
}