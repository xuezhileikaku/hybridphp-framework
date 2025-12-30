<?php

declare(strict_types=1);

namespace HybridPHP\Core\Debug;

use Psr\Log\LoggerInterface;

/**
 * Performance profiler for debugging and analysis
 */
class PerformanceProfiler
{
    private array $timers = [];
    private array $memorySnapshots = [];
    private array $queryLog = [];
    private array $coroutineMetrics = [];
    private float $startTime;
    private int $startMemory;
    private ?LoggerInterface $logger;
    private bool $enabled = true;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);
    }

    /**
     * Start a timer
     */
    public function startTimer(string $name, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->timers[$name] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'context' => $context,
            'running' => true,
        ];
    }

    /**
     * Stop a timer
     */
    public function stopTimer(string $name): ?array
    {
        if (!$this->enabled || !isset($this->timers[$name]) || !$this->timers[$name]['running']) {
            return null;
        }

        $timer = &$this->timers[$name];
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $timer['end_time'] = $endTime;
        $timer['end_memory'] = $endMemory;
        $timer['duration'] = $endTime - $timer['start_time'];
        $timer['memory_used'] = $endMemory - $timer['start_memory'];
        $timer['running'] = false;

        return $timer;
    }

    /**
     * Record a memory snapshot
     */
    public function recordMemorySnapshot(string $label): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->memorySnapshots[] = [
            'label' => $label,
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'memory_real' => memory_get_usage(false),
        ];
    }

    /**
     * Log a database query
     */
    public function logQuery(string $sql, array $bindings = [], float $duration = 0, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->queryLog[] = [
            'sql' => $sql,
            'bindings' => $bindings,
            'duration' => $duration,
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'context' => $context,
            'backtrace' => $this->getQueryBacktrace(),
        ];
    }

    /**
     * Record coroutine metrics
     */
    public function recordCoroutine(string $id, string $name, string $status, array $data = []): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!isset($this->coroutineMetrics[$id])) {
            $this->coroutineMetrics[$id] = [
                'id' => $id,
                'name' => $name,
                'created_at' => microtime(true),
                'status_history' => [],
            ];
        }

        $this->coroutineMetrics[$id]['status'] = $status;
        $this->coroutineMetrics[$id]['updated_at'] = microtime(true);
        $this->coroutineMetrics[$id]['status_history'][] = [
            'status' => $status,
            'timestamp' => microtime(true),
            'data' => $data,
        ];

        if ($status === 'completed' || $status === 'failed') {
            $this->coroutineMetrics[$id]['duration'] = 
                microtime(true) - $this->coroutineMetrics[$id]['created_at'];
        }
    }

    /**
     * Get performance snapshot
     */
    public function getSnapshot(): array
    {
        $currentTime = microtime(true);
        $currentMemory = memory_get_usage(true);

        return [
            'execution_time' => $currentTime - $this->startTime,
            'memory_usage' => $currentMemory,
            'memory_start' => $this->startMemory,
            'memory_used' => $currentMemory - $this->startMemory,
            'peak_memory' => memory_get_peak_usage(true),
            'active_coroutines' => $this->getActiveCoroutineCount(),
            'completed_timers' => $this->getCompletedTimers(),
            'running_timers' => $this->getRunningTimers(),
            'query_count' => count($this->queryLog),
            'total_query_time' => $this->getTotalQueryTime(),
        ];
    }

    /**
     * Get detailed performance report
     */
    public function getDetailedReport(): array
    {
        return [
            'summary' => $this->getSnapshot(),
            'timers' => $this->timers,
            'memory_snapshots' => $this->memorySnapshots,
            'queries' => $this->getQueryAnalysis(),
            'coroutines' => $this->getCoroutineAnalysis(),
            'system_info' => $this->getSystemInfo(),
        ];
    }

    /**
     * Get query analysis
     */
    public function getQueryAnalysis(): array
    {
        $analysis = [
            'total_queries' => count($this->queryLog),
            'total_time' => $this->getTotalQueryTime(),
            'slow_queries' => [],
            'duplicate_queries' => [],
            'query_types' => [],
            'queries' => $this->queryLog,
        ];

        // Analyze slow queries (> 100ms)
        foreach ($this->queryLog as $query) {
            if ($query['duration'] > 0.1) {
                $analysis['slow_queries'][] = $query;
            }

            // Count query types
            $type = $this->getQueryType($query['sql']);
            $analysis['query_types'][$type] = ($analysis['query_types'][$type] ?? 0) + 1;
        }

        // Find duplicate queries
        $sqlCounts = [];
        foreach ($this->queryLog as $query) {
            $normalizedSql = $this->normalizeQuery($query['sql']);
            $sqlCounts[$normalizedSql] = ($sqlCounts[$normalizedSql] ?? 0) + 1;
        }

        foreach ($sqlCounts as $sql => $count) {
            if ($count > 1) {
                $analysis['duplicate_queries'][] = [
                    'sql' => $sql,
                    'count' => $count,
                ];
            }
        }

        // Sort slow queries by duration
        usort($analysis['slow_queries'], function ($a, $b) {
            return $b['duration'] <=> $a['duration'];
        });

        return $analysis;
    }

    /**
     * Get coroutine analysis
     */
    public function getCoroutineAnalysis(): array
    {
        $analysis = [
            'total_coroutines' => count($this->coroutineMetrics),
            'active_coroutines' => $this->getActiveCoroutineCount(),
            'completed_coroutines' => 0,
            'failed_coroutines' => 0,
            'average_duration' => 0,
            'longest_running' => null,
            'coroutines' => [],
        ];

        $totalDuration = 0;
        $completedCount = 0;
        $longestDuration = 0;

        foreach ($this->coroutineMetrics as $coroutine) {
            $analysis['coroutines'][] = $coroutine;

            if ($coroutine['status'] === 'completed') {
                $analysis['completed_coroutines']++;
                if (isset($coroutine['duration'])) {
                    $totalDuration += $coroutine['duration'];
                    $completedCount++;

                    if ($coroutine['duration'] > $longestDuration) {
                        $longestDuration = $coroutine['duration'];
                        $analysis['longest_running'] = $coroutine;
                    }
                }
            } elseif ($coroutine['status'] === 'failed') {
                $analysis['failed_coroutines']++;
            }
        }

        if ($completedCount > 0) {
            $analysis['average_duration'] = $totalDuration / $completedCount;
        }

        return $analysis;
    }

    /**
     * Get system information
     */
    public function getSystemInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'php_sapi' => php_sapi_name(),
            'os' => PHP_OS,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'opcache_enabled' => extension_loaded('opcache') && opcache_get_status() !== false,
            'xdebug_enabled' => extension_loaded('xdebug'),
            'loaded_extensions' => get_loaded_extensions(),
            'server_info' => [
                'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
                'request_time' => $_SERVER['REQUEST_TIME'] ?? time(),
            ],
        ];
    }

    /**
     * Export profile data for external analysis
     */
    public function exportProfile(string $format = 'json'): string
    {
        $data = $this->getDetailedReport();

        switch ($format) {
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT);
            
            case 'csv':
                return $this->exportToCsv($data);
            
            case 'html':
                return $this->exportToHtml($data);
            
            default:
                throw new \InvalidArgumentException("Unsupported format: {$format}");
        }
    }

    /**
     * Clear all profiling data
     */
    public function clear(): void
    {
        $this->timers = [];
        $this->memorySnapshots = [];
        $this->queryLog = [];
        $this->coroutineMetrics = [];
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);
    }

    /**
     * Enable/disable profiling
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Check if profiling is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get completed timers
     */
    private function getCompletedTimers(): array
    {
        return array_filter($this->timers, function ($timer) {
            return !$timer['running'];
        });
    }

    /**
     * Get running timers
     */
    private function getRunningTimers(): array
    {
        return array_filter($this->timers, function ($timer) {
            return $timer['running'];
        });
    }

    /**
     * Get active coroutine count
     */
    private function getActiveCoroutineCount(): int
    {
        $count = 0;
        foreach ($this->coroutineMetrics as $coroutine) {
            if (!in_array($coroutine['status'], ['completed', 'failed'])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get total query time
     */
    private function getTotalQueryTime(): float
    {
        $total = 0;
        foreach ($this->queryLog as $query) {
            $total += $query['duration'];
        }
        return $total;
    }

    /**
     * Get query backtrace
     */
    private function getQueryBacktrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $filtered = [];

        foreach ($trace as $frame) {
            if (isset($frame['file']) && !str_contains($frame['file'], 'PerformanceProfiler.php')) {
                $filtered[] = [
                    'file' => $frame['file'],
                    'line' => $frame['line'] ?? 0,
                    'function' => $frame['function'] ?? 'unknown',
                    'class' => $frame['class'] ?? null,
                ];
            }
        }

        return array_slice($filtered, 0, 5);
    }

    /**
     * Get query type from SQL
     */
    private function getQueryType(string $sql): string
    {
        $sql = trim(strtoupper($sql));
        
        if (strpos($sql, 'SELECT') === 0) return 'SELECT';
        if (strpos($sql, 'INSERT') === 0) return 'INSERT';
        if (strpos($sql, 'UPDATE') === 0) return 'UPDATE';
        if (strpos($sql, 'DELETE') === 0) return 'DELETE';
        if (strpos($sql, 'CREATE') === 0) return 'CREATE';
        if (strpos($sql, 'ALTER') === 0) return 'ALTER';
        if (strpos($sql, 'DROP') === 0) return 'DROP';
        
        return 'OTHER';
    }

    /**
     * Normalize query for duplicate detection
     */
    private function normalizeQuery(string $sql): string
    {
        // Remove extra whitespace
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        
        // Replace parameter placeholders
        $sql = preg_replace('/\?/', '?', $sql);
        $sql = preg_replace('/:\w+/', '?', $sql);
        
        // Replace numeric values
        $sql = preg_replace('/\b\d+\b/', '?', $sql);
        
        // Replace string literals
        $sql = preg_replace("/'[^']*'/", '?', $sql);
        $sql = preg_replace('/"[^"]*"/', '?', $sql);
        
        return $sql;
    }

    /**
     * Export to CSV format
     */
    private function exportToCsv(array $data): string
    {
        $csv = "Type,Name,Duration,Memory,Details\n";
        
        foreach ($data['timers'] as $name => $timer) {
            if (!$timer['running']) {
                $csv .= sprintf(
                    "Timer,%s,%.4f,%d,%s\n",
                    $name,
                    $timer['duration'],
                    $timer['memory_used'],
                    json_encode($timer['context'])
                );
            }
        }
        
        foreach ($data['queries']['queries'] as $query) {
            $csv .= sprintf(
                "Query,%s,%.4f,%d,%s\n",
                substr($query['sql'], 0, 50),
                $query['duration'],
                $query['memory_usage'],
                json_encode($query['bindings'])
            );
        }
        
        return $csv;
    }

    /**
     * Export to HTML format
     */
    private function exportToHtml(array $data): string
    {
        $summary = $data['summary'];
        
        return "
<!DOCTYPE html>
<html>
<head>
    <title>Performance Profile Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .summary { background: #f0f0f0; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .metric { display: inline-block; margin: 10px; padding: 10px; background: white; border-radius: 3px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .slow-query { background-color: #ffebee; }
    </style>
</head>
<body>
    <h1>Performance Profile Report</h1>
    
    <div class='summary'>
        <h2>Summary</h2>
        <div class='metric'>
            <strong>Execution Time:</strong> " . number_format($summary['execution_time'], 4) . "s
        </div>
        <div class='metric'>
            <strong>Memory Used:</strong> " . number_format($summary['memory_used'] / 1024 / 1024, 2) . " MB
        </div>
        <div class='metric'>
            <strong>Peak Memory:</strong> " . number_format($summary['peak_memory'] / 1024 / 1024, 2) . " MB
        </div>
        <div class='metric'>
            <strong>Queries:</strong> {$summary['query_count']}
        </div>
        <div class='metric'>
            <strong>Query Time:</strong> " . number_format($summary['total_query_time'], 4) . "s
        </div>
    </div>
    
    <h2>Slow Queries</h2>
    <table>
        <tr><th>SQL</th><th>Duration</th><th>Bindings</th></tr>";
        
        foreach ($data['queries']['slow_queries'] as $query) {
            $html .= "<tr class='slow-query'>
                <td>" . htmlspecialchars(substr($query['sql'], 0, 100)) . "</td>
                <td>" . number_format($query['duration'], 4) . "s</td>
                <td>" . htmlspecialchars(json_encode($query['bindings'])) . "</td>
            </tr>";
        }
        
        $html .= "
    </table>
</body>
</html>";
        
        return $html;
    }
}