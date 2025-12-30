<?php

declare(strict_types=1);

namespace HybridPHP\Core\Console\Commands;

use HybridPHP\Core\Debug\PerformanceProfiler;
use HybridPHP\Core\Debug\CoroutineDebugger;
use HybridPHP\Core\Debug\QueryAnalyzer;
use HybridPHP\Core\Debug\DebugServiceProvider;
use HybridPHP\Core\Container;

/**
 * Debug command for analyzing application performance and issues
 */
class DebugCommand
{
    private Container $container;
    private DebugServiceProvider $debugProvider;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->debugProvider = $container->get(DebugServiceProvider::class);
    }

    /**
     * Execute debug command
     */
    public function execute(array $args = []): int
    {
        $command = $args[0] ?? 'status';

        switch ($command) {
            case 'status':
                return $this->showStatus();
            
            case 'profiler':
                return $this->showProfilerReport();
            
            case 'coroutines':
                return $this->showCoroutineReport();
            
            case 'queries':
                return $this->showQueryReport();
            
            case 'export':
                return $this->exportDebugData($args[1] ?? 'json');
            
            case 'clear':
                return $this->clearDebugData();
            
            case 'enable':
                return $this->enableDebugging();
            
            case 'disable':
                return $this->disableDebugging();
            
            default:
                $this->showHelp();
                return 1;
        }
    }

    /**
     * Show debug status
     */
    private function showStatus(): int
    {
        $this->printHeader('Debug Status');
        
        $status = $this->debugProvider->getDebugStatus();
        
        echo "Debug Mode: " . ($status['debug_mode'] ? 'âœ?Enabled' : 'âœ?Disabled') . "\n";
        echo "Profiler: " . ($status['profiler_enabled'] ? 'âœ?Enabled' : 'âœ?Disabled') . "\n";
        echo "Coroutine Debugger: " . ($status['coroutine_debugger_enabled'] ? 'âœ?Enabled' : 'âœ?Disabled') . "\n";
        echo "Query Analyzer: " . ($status['query_analyzer_enabled'] ? 'âœ?Enabled' : 'âœ?Disabled') . "\n";
        echo "Error Handler: " . ($status['error_handler_enabled'] ? 'âœ?Enabled' : 'âœ?Disabled') . "\n";
        
        echo "\nServices Status:\n";
        foreach ($status['services_registered'] as $service => $registered) {
            echo "  {$service}: " . ($registered ? 'âœ?Registered' : 'âœ?Not Registered') . "\n";
        }

        return 0;
    }

    /**
     * Show profiler report
     */
    private function showProfilerReport(): int
    {
        if (!$this->container->has(PerformanceProfiler::class)) {
            echo "â?Performance profiler not available\n";
            return 1;
        }

        $profiler = $this->container->get(PerformanceProfiler::class);
        $report = $profiler->getDetailedReport();

        $this->printHeader('Performance Profiler Report');

        // Summary
        $summary = $report['summary'];
        echo "Execution Time: " . number_format($summary['execution_time'], 4) . "s\n";
        echo "Memory Used: " . $this->formatBytes($summary['memory_used']) . "\n";
        echo "Peak Memory: " . $this->formatBytes($summary['peak_memory']) . "\n";
        echo "Query Count: " . $summary['query_count'] . "\n";
        echo "Total Query Time: " . number_format($summary['total_query_time'], 4) . "s\n";

        // Timers
        if (!empty($report['timers'])) {
            echo "\n" . str_repeat('=', 60) . "\n";
            echo "EXECUTION TIMERS\n";
            echo str_repeat('=', 60) . "\n";
            
            foreach ($report['timers'] as $name => $timer) {
                if (!$timer['running']) {
                    echo sprintf(
                        "%-30s %8.4fs %10s\n",
                        $name,
                        $timer['duration'],
                        $this->formatBytes($timer['memory_used'])
                    );
                }
            }
        }

        // Memory snapshots
        if (!empty($report['memory_snapshots'])) {
            echo "\n" . str_repeat('=', 60) . "\n";
            echo "MEMORY SNAPSHOTS\n";
            echo str_repeat('=', 60) . "\n";
            
            foreach (array_slice($report['memory_snapshots'], -10) as $snapshot) {
                echo sprintf(
                    "%-30s %10s %10s\n",
                    $snapshot['label'],
                    $this->formatBytes($snapshot['memory_usage']),
                    $this->formatBytes($snapshot['memory_peak'])
                );
            }
        }

        return 0;
    }

    /**
     * Show coroutine report
     */
    private function showCoroutineReport(): int
    {
        if (!$this->container->has(CoroutineDebugger::class)) {
            echo "â?Coroutine debugger not available\n";
            return 1;
        }

        $debugger = $this->container->get(CoroutineDebugger::class);
        $report = $debugger->getDetailedReport();

        $this->printHeader('Coroutine Debug Report');

        // Statistics
        $stats = $report['statistics'];
        echo "Total Coroutines: " . $stats['total_coroutines'] . "\n";
        echo "Active: " . $stats['active_coroutines'] . "\n";
        echo "Completed: " . $stats['completed_coroutines'] . "\n";
        echo "Failed: " . $stats['failed_coroutines'] . "\n";
        echo "Success Rate: " . number_format($stats['success_rate'], 1) . "%\n";
        echo "Average Duration: " . number_format($stats['average_duration'], 4) . "s\n";

        // Active coroutines
        if (!empty($report['active_coroutines'])) {
            echo "\n" . str_repeat('=', 80) . "\n";
            echo "ACTIVE COROUTINES\n";
            echo str_repeat('=', 80) . "\n";
            
            foreach ($report['active_coroutines'] as $coroutine) {
                $runningTime = microtime(true) - $coroutine['created_at'];
                echo sprintf(
                    "%-20s %-15s %-10s %8.2fs\n",
                    substr($coroutine['name'], 0, 20),
                    $coroutine['id'],
                    $coroutine['status'],
                    $runningTime
                );
            }
        }

        // Slow coroutines
        if (!empty($report['slow_coroutines'])) {
            echo "\n" . str_repeat('=', 80) . "\n";
            echo "SLOW COROUTINES (>" . $stats['slow_threshold'] . "s)\n";
            echo str_repeat('=', 80) . "\n";
            
            foreach (array_slice($report['slow_coroutines'], 0, 10) as $coroutine) {
                echo sprintf(
                    "%-20s %-15s %8.4fs %10s\n",
                    substr($coroutine['name'], 0, 20),
                    $coroutine['id'],
                    $coroutine['duration'] ?? 0,
                    $this->formatBytes(($coroutine['memory_end'] ?? 0) - $coroutine['memory_start'])
                );
            }
        }

        // Failed coroutines
        if (!empty($report['failed_coroutines'])) {
            echo "\n" . str_repeat('=', 80) . "\n";
            echo "FAILED COROUTINES\n";
            echo str_repeat('=', 80) . "\n";
            
            foreach ($report['failed_coroutines'] as $coroutine) {
                echo sprintf(
                    "%-20s %-15s %s\n",
                    substr($coroutine['name'], 0, 20),
                    $coroutine['id'],
                    $coroutine['error'] ?? 'Unknown error'
                );
            }
        }

        return 0;
    }

    /**
     * Show query report
     */
    private function showQueryReport(): int
    {
        if (!$this->container->has(QueryAnalyzer::class)) {
            echo "â?Query analyzer not available\n";
            return 1;
        }

        $analyzer = $this->container->get(QueryAnalyzer::class);
        $report = $analyzer->getAnalysisReport();

        $this->printHeader('Query Analysis Report');

        // Statistics
        $stats = $report['statistics'];
        echo "Total Queries: " . $stats['total_queries'] . "\n";
        echo "Slow Queries: " . $stats['slow_queries'] . " (" . number_format($stats['slow_query_percentage'], 1) . "%)\n";
        echo "Duplicate Queries: " . $stats['duplicate_queries'] . "\n";
        echo "Average Duration: " . number_format($stats['average_duration'], 4) . "s\n";
        echo "Total Duration: " . number_format($stats['total_duration'], 4) . "s\n";

        // Query types
        if (!empty($stats['query_types'])) {
            echo "\nQuery Types:\n";
            foreach ($stats['query_types'] as $type => $count) {
                echo "  {$type}: {$count}\n";
            }
        }

        // Performance issues
        if (!empty($report['performance_issues'])) {
            echo "\n" . str_repeat('=', 80) . "\n";
            echo "PERFORMANCE ISSUES\n";
            echo str_repeat('=', 80) . "\n";
            
            foreach ($report['performance_issues'] as $issue) {
                echo "â?[{$issue['severity']}] {$issue['message']}\n";
                echo "   Recommendation: {$issue['recommendation']}\n\n";
            }
        }

        // Slow queries
        if (!empty($report['slow_queries'])) {
            echo "\n" . str_repeat('=', 80) . "\n";
            echo "SLOW QUERIES (>" . $stats['slow_threshold'] . "s)\n";
            echo str_repeat('=', 80) . "\n";
            
            foreach (array_slice($report['slow_queries'], 0, 10) as $query) {
                echo sprintf(
                    "%8.4fs %-8s %s\n",
                    $query['duration'],
                    $query['type'],
                    substr(str_replace(["\n", "\r", "\t"], ' ', $query['sql']), 0, 60) . '...'
                );
            }
        }

        // Duplicate queries
        if (!empty($report['duplicate_queries'])) {
            echo "\n" . str_repeat('=', 80) . "\n";
            echo "DUPLICATE QUERIES\n";
            echo str_repeat('=', 80) . "\n";
            
            foreach (array_slice($report['duplicate_queries'], 0, 5) as $duplicate) {
                echo sprintf(
                    "%3dx %8.4fs %s\n",
                    $duplicate['count'],
                    $duplicate['total_duration'],
                    substr(str_replace(["\n", "\r", "\t"], ' ', $duplicate['normalized_sql']), 0, 60) . '...'
                );
            }
        }

        // Recommendations
        if (!empty($report['recommendations'])) {
            echo "\n" . str_repeat('=', 80) . "\n";
            echo "RECOMMENDATIONS\n";
            echo str_repeat('=', 80) . "\n";
            
            foreach ($report['recommendations'] as $rec) {
                echo "ðŸ’¡ [{$rec['priority']}] {$rec['title']}\n";
                echo "   {$rec['description']}\n\n";
            }
        }

        return 0;
    }

    /**
     * Export debug data
     */
    private function exportDebugData(string $format): int
    {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "debug_report_{$timestamp}.{$format}";

        try {
            $data = [];

            // Collect profiler data
            if ($this->container->has(PerformanceProfiler::class)) {
                $profiler = $this->container->get(PerformanceProfiler::class);
                $data['profiler'] = $profiler->getDetailedReport();
            }

            // Collect coroutine data
            if ($this->container->has(CoroutineDebugger::class)) {
                $debugger = $this->container->get(CoroutineDebugger::class);
                $data['coroutines'] = $debugger->getDetailedReport();
            }

            // Collect query data
            if ($this->container->has(QueryAnalyzer::class)) {
                $analyzer = $this->container->get(QueryAnalyzer::class);
                $data['queries'] = $analyzer->getAnalysisReport();
            }

            // Export data
            switch ($format) {
                case 'json':
                    $content = json_encode($data, JSON_PRETTY_PRINT);
                    break;
                
                case 'csv':
                    $content = $this->exportToCsv($data);
                    break;
                
                default:
                    echo "â?Unsupported format: {$format}\n";
                    return 1;
            }

            file_put_contents($filename, $content);
            echo "âœ?Debug data exported to: {$filename}\n";
            echo "   File size: " . $this->formatBytes(filesize($filename)) . "\n";

            return 0;
        } catch (\Throwable $e) {
            echo "â?Export failed: " . $e->getMessage() . "\n";
            return 1;
        }
    }

    /**
     * Clear debug data
     */
    private function clearDebugData(): int
    {
        $cleared = [];

        if ($this->container->has(PerformanceProfiler::class)) {
            $this->container->get(PerformanceProfiler::class)->clear();
            $cleared[] = 'profiler';
        }

        if ($this->container->has(CoroutineDebugger::class)) {
            $this->container->get(CoroutineDebugger::class)->clear();
            $cleared[] = 'coroutine debugger';
        }

        if ($this->container->has(QueryAnalyzer::class)) {
            $this->container->get(QueryAnalyzer::class)->clear();
            $cleared[] = 'query analyzer';
        }

        if (empty($cleared)) {
            echo "â?No debug services available to clear\n";
            return 1;
        }

        echo "âœ?Cleared debug data for: " . implode(', ', $cleared) . "\n";
        return 0;
    }

    /**
     * Enable debugging
     */
    private function enableDebugging(): int
    {
        $this->debugProvider->setDebugMode(true);
        echo "âœ?Debug mode enabled\n";
        return 0;
    }

    /**
     * Disable debugging
     */
    private function disableDebugging(): int
    {
        $this->debugProvider->setDebugMode(false);
        echo "âœ?Debug mode disabled\n";
        return 0;
    }

    /**
     * Show help
     */
    private function showHelp(): void
    {
        echo "HybridPHP Debug Tool\n\n";
        echo "Usage: php debug.php <command> [options]\n\n";
        echo "Commands:\n";
        echo "  status      Show debug status and configuration\n";
        echo "  profiler    Show performance profiler report\n";
        echo "  coroutines  Show coroutine debugging report\n";
        echo "  queries     Show query analysis report\n";
        echo "  export      Export debug data (json|csv)\n";
        echo "  clear       Clear all debug data\n";
        echo "  enable      Enable debug mode\n";
        echo "  disable     Disable debug mode\n";
        echo "  help        Show this help message\n\n";
        echo "Examples:\n";
        echo "  php debug.php status\n";
        echo "  php debug.php profiler\n";
        echo "  php debug.php export json\n";
        echo "  php debug.php clear\n";
    }

    /**
     * Print header
     */
    private function printHeader(string $title): void
    {
        $length = strlen($title);
        echo "\n" . str_repeat('=', $length + 4) . "\n";
        echo "  {$title}\n";
        echo str_repeat('=', $length + 4) . "\n\n";
    }

    /**
     * Format bytes
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string)$bytes) - 1) / 3);
        
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * Export to CSV format
     */
    private function exportToCsv(array $data): string
    {
        $csv = "Type,Name,Value,Details,Timestamp\n";
        
        // Export profiler data
        if (isset($data['profiler']['timers'])) {
            foreach ($data['profiler']['timers'] as $name => $timer) {
                if (!$timer['running']) {
                    $csv .= sprintf(
                        "Timer,%s,%.4f,%s,%s\n",
                        $name,
                        $timer['duration'],
                        json_encode($timer['context']),
                        date('Y-m-d H:i:s', (int)$timer['start_time'])
                    );
                }
            }
        }
        
        // Export query data
        if (isset($data['queries']['slow_queries'])) {
            foreach ($data['queries']['slow_queries'] as $query) {
                $csv .= sprintf(
                    "Query,%s,%.4f,%s,%s\n",
                    substr($query['sql'], 0, 50),
                    $query['duration'],
                    json_encode($query['bindings']),
                    date('Y-m-d H:i:s', (int)$query['timestamp'])
                );
            }
        }
        
        // Export coroutine data
        if (isset($data['coroutines']['slow_coroutines'])) {
            foreach ($data['coroutines']['slow_coroutines'] as $coroutine) {
                $csv .= sprintf(
                    "Coroutine,%s,%.4f,%s,%s\n",
                    $coroutine['name'],
                    $coroutine['duration'] ?? 0,
                    json_encode($coroutine['context'] ?? []),
                    date('Y-m-d H:i:s', (int)$coroutine['created_at'])
                );
            }
        }
        
        return $csv;
    }
}