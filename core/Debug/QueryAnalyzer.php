<?php

declare(strict_types=1);

namespace HybridPHP\Core\Debug;

use Psr\Log\LoggerInterface;

/**
 * SQL query analyzer for performance debugging
 */
class QueryAnalyzer
{
    private array $queries = [];
    private array $queryPlans = [];
    private ?LoggerInterface $logger;
    private bool $enabled = true;
    private float $slowQueryThreshold = 0.1; // 100ms
    private int $maxQueries = 1000;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Record a query execution
     */
    public function recordQuery(
        string $sql,
        array $bindings = [],
        float $duration = 0,
        array $context = []
    ): void {
        if (!$this->enabled) {
            return;
        }

        $queryId = uniqid('query_', true);
        $normalizedSql = $this->normalizeQuery($sql);
        
        $query = [
            'id' => $queryId,
            'sql' => $sql,
            'normalized_sql' => $normalizedSql,
            'bindings' => $bindings,
            'duration' => $duration,
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
            'context' => $context,
            'backtrace' => $this->getQueryBacktrace(),
            'type' => $this->getQueryType($sql),
            'is_slow' => $duration > $this->slowQueryThreshold,
        ];

        $this->queries[] = $query;

        // Log slow queries
        if ($query['is_slow'] && $this->logger) {
            $this->logger->warning('Slow query detected', [
                'query_id' => $queryId,
                'sql' => $sql,
                'duration' => $duration,
                'threshold' => $this->slowQueryThreshold,
                'bindings' => $bindings,
            ]);
        }

        // Cleanup old queries to prevent memory leaks
        if (count($this->queries) > $this->maxQueries) {
            array_shift($this->queries);
        }
    }

    /**
     * Record query execution plan
     */
    public function recordQueryPlan(string $queryId, array $plan): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->queryPlans[$queryId] = [
            'query_id' => $queryId,
            'plan' => $plan,
            'timestamp' => microtime(true),
            'analysis' => $this->analyzeQueryPlan($plan),
        ];
    }

    /**
     * Get all recorded queries
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * Get slow queries
     */
    public function getSlowQueries(): array
    {
        return array_filter($this->queries, function ($query) {
            return $query['is_slow'];
        });
    }

    /**
     * Get duplicate queries
     */
    public function getDuplicateQueries(): array
    {
        $normalized = [];
        $duplicates = [];

        foreach ($this->queries as $query) {
            $key = $query['normalized_sql'];
            
            if (!isset($normalized[$key])) {
                $normalized[$key] = [];
            }
            
            $normalized[$key][] = $query;
        }

        foreach ($normalized as $sql => $queries) {
            if (count($queries) > 1) {
                $duplicates[$sql] = [
                    'normalized_sql' => $sql,
                    'count' => count($queries),
                    'total_duration' => array_sum(array_column($queries, 'duration')),
                    'queries' => $queries,
                ];
            }
        }

        // Sort by count descending
        uasort($duplicates, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return $duplicates;
    }

    /**
     * Get query statistics
     */
    public function getStatistics(): array
    {
        $total = count($this->queries);
        $slow = count($this->getSlowQueries());
        $duplicates = count($this->getDuplicateQueries());
        
        $totalDuration = array_sum(array_column($this->queries, 'duration'));
        $avgDuration = $total > 0 ? $totalDuration / $total : 0;
        
        $queryTypes = [];
        foreach ($this->queries as $query) {
            $type = $query['type'];
            $queryTypes[$type] = ($queryTypes[$type] ?? 0) + 1;
        }

        return [
            'total_queries' => $total,
            'slow_queries' => $slow,
            'duplicate_queries' => $duplicates,
            'total_duration' => $totalDuration,
            'average_duration' => $avgDuration,
            'slow_query_percentage' => $total > 0 ? ($slow / $total) * 100 : 0,
            'query_types' => $queryTypes,
            'slow_threshold' => $this->slowQueryThreshold,
        ];
    }

    /**
     * Get detailed analysis report
     */
    public function getAnalysisReport(): array
    {
        return [
            'statistics' => $this->getStatistics(),
            'slow_queries' => $this->getSlowQueries(),
            'duplicate_queries' => $this->getDuplicateQueries(),
            'query_patterns' => $this->analyzeQueryPatterns(),
            'performance_issues' => $this->identifyPerformanceIssues(),
            'recommendations' => $this->generateRecommendations(),
        ];
    }

    /**
     * Analyze query patterns
     */
    public function analyzeQueryPatterns(): array
    {
        $patterns = [
            'n_plus_one' => $this->detectNPlusOneQueries(),
            'missing_indexes' => $this->detectMissingIndexes(),
            'inefficient_joins' => $this->detectInefficientJoins(),
            'large_result_sets' => $this->detectLargeResultSets(),
        ];

        return $patterns;
    }

    /**
     * Identify performance issues
     */
    public function identifyPerformanceIssues(): array
    {
        $issues = [];

        // Check for excessive query count
        if (count($this->queries) > 100) {
            $issues[] = [
                'type' => 'excessive_queries',
                'severity' => 'high',
                'message' => 'High number of queries detected (' . count($this->queries) . ')',
                'recommendation' => 'Consider query optimization, caching, or eager loading',
            ];
        }

        // Check for slow query percentage
        $stats = $this->getStatistics();
        if ($stats['slow_query_percentage'] > 10) {
            $issues[] = [
                'type' => 'high_slow_query_rate',
                'severity' => 'high',
                'message' => sprintf('%.1f%% of queries are slow', $stats['slow_query_percentage']),
                'recommendation' => 'Review and optimize slow queries, add indexes',
            ];
        }

        // Check for duplicate queries
        if (count($this->getDuplicateQueries()) > 5) {
            $issues[] = [
                'type' => 'duplicate_queries',
                'severity' => 'medium',
                'message' => 'Multiple duplicate queries detected',
                'recommendation' => 'Implement query result caching or optimize data access patterns',
            ];
        }

        return $issues;
    }

    /**
     * Generate optimization recommendations
     */
    public function generateRecommendations(): array
    {
        $recommendations = [];
        $stats = $this->getStatistics();
        $duplicates = $this->getDuplicateQueries();

        // Slow query recommendations
        if ($stats['slow_queries'] > 0) {
            $recommendations[] = [
                'category' => 'performance',
                'priority' => 'high',
                'title' => 'Optimize Slow Queries',
                'description' => "Found {$stats['slow_queries']} slow queries. Consider adding indexes, optimizing WHERE clauses, or restructuring queries.",
                'queries' => array_slice($this->getSlowQueries(), 0, 5),
            ];
        }

        // Duplicate query recommendations
        if (!empty($duplicates)) {
            $topDuplicate = array_values($duplicates)[0];
            $recommendations[] = [
                'category' => 'caching',
                'priority' => 'medium',
                'title' => 'Implement Query Caching',
                'description' => "Found duplicate queries. Top duplicate executed {$topDuplicate['count']} times.",
                'suggestion' => 'Implement result caching or optimize data access patterns',
            ];
        }

        // Query type recommendations
        if (isset($stats['query_types']['SELECT']) && $stats['query_types']['SELECT'] > 80) {
            $recommendations[] = [
                'category' => 'architecture',
                'priority' => 'low',
                'title' => 'Consider Read Replicas',
                'description' => 'High number of SELECT queries detected. Consider implementing read replicas for better performance.',
            ];
        }

        return $recommendations;
    }

    /**
     * Export analysis data
     */
    public function exportAnalysis(string $format = 'json'): string
    {
        $data = $this->getAnalysisReport();

        switch ($format) {
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT);
            
            case 'csv':
                return $this->exportToCsv();
            
            case 'html':
                return $this->exportToHtml($data);
            
            default:
                throw new \InvalidArgumentException("Unsupported format: {$format}");
        }
    }

    /**
     * Clear all recorded queries
     */
    public function clear(): void
    {
        $this->queries = [];
        $this->queryPlans = [];
    }

    /**
     * Set slow query threshold
     */
    public function setSlowQueryThreshold(float $seconds): void
    {
        $this->slowQueryThreshold = $seconds;
    }

    /**
     * Enable/disable query analysis
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
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
        
        return strtoupper($sql);
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
     * Get query backtrace
     */
    private function getQueryBacktrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $filtered = [];

        foreach ($trace as $frame) {
            if (isset($frame['file']) && !str_contains($frame['file'], 'QueryAnalyzer.php')) {
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
     * Analyze query execution plan
     */
    private function analyzeQueryPlan(array $plan): array
    {
        $analysis = [
            'issues' => [],
            'recommendations' => [],
            'cost_estimate' => 0,
        ];

        // This is a simplified analysis - in a real implementation,
        // you would analyze the actual database execution plan
        foreach ($plan as $step) {
            if (isset($step['type'])) {
                switch ($step['type']) {
                    case 'table_scan':
                        $analysis['issues'][] = 'Full table scan detected';
                        $analysis['recommendations'][] = 'Consider adding an index';
                        break;
                    
                    case 'nested_loop':
                        if (isset($step['rows']) && $step['rows'] > 1000) {
                            $analysis['issues'][] = 'Large nested loop join';
                            $analysis['recommendations'][] = 'Consider optimizing join conditions';
                        }
                        break;
                }
            }
        }

        return $analysis;
    }

    /**
     * Detect N+1 query problems
     */
    private function detectNPlusOneQueries(): array
    {
        $patterns = [];
        $timeWindow = 1.0; // 1 second window
        
        // Group queries by normalized SQL and time
        $groups = [];
        foreach ($this->queries as $query) {
            $key = $query['normalized_sql'];
            $timeSlot = floor($query['timestamp'] / $timeWindow);
            $groupKey = $key . '_' . $timeSlot;
            
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [];
            }
            
            $groups[$groupKey][] = $query;
        }

        // Find groups with many similar queries
        foreach ($groups as $group) {
            if (count($group) > 10) { // Threshold for N+1 detection
                $patterns[] = [
                    'type' => 'n_plus_one',
                    'query_count' => count($group),
                    'normalized_sql' => $group[0]['normalized_sql'],
                    'time_window' => $timeWindow,
                    'recommendation' => 'Consider using eager loading or batch queries',
                ];
            }
        }

        return $patterns;
    }

    /**
     * Detect missing indexes
     */
    private function detectMissingIndexes(): array
    {
        $issues = [];
        
        foreach ($this->getSlowQueries() as $query) {
            if ($query['type'] === 'SELECT' && $query['duration'] > 0.5) {
                // Simple heuristic: slow SELECT queries might need indexes
                $issues[] = [
                    'query_id' => $query['id'],
                    'sql' => $query['sql'],
                    'duration' => $query['duration'],
                    'suggestion' => 'Consider adding indexes on WHERE/JOIN columns',
                ];
            }
        }

        return $issues;
    }

    /**
     * Detect inefficient joins
     */
    private function detectInefficientJoins(): array
    {
        $issues = [];
        
        foreach ($this->queries as $query) {
            if (stripos($query['sql'], 'JOIN') !== false && $query['duration'] > 0.2) {
                $issues[] = [
                    'query_id' => $query['id'],
                    'sql' => $query['sql'],
                    'duration' => $query['duration'],
                    'suggestion' => 'Review JOIN conditions and ensure proper indexing',
                ];
            }
        }

        return $issues;
    }

    /**
     * Detect large result sets
     */
    private function detectLargeResultSets(): array
    {
        $issues = [];
        
        foreach ($this->queries as $query) {
            if ($query['type'] === 'SELECT' && 
                $query['duration'] > 0.3 && 
                stripos($query['sql'], 'LIMIT') === false) {
                $issues[] = [
                    'query_id' => $query['id'],
                    'sql' => $query['sql'],
                    'duration' => $query['duration'],
                    'suggestion' => 'Consider adding LIMIT clause or pagination',
                ];
            }
        }

        return $issues;
    }

    /**
     * Export to CSV format
     */
    private function exportToCsv(): string
    {
        $csv = "ID,Type,Duration,SQL,Bindings,Is Slow,Timestamp\n";
        
        foreach ($this->queries as $query) {
            $csv .= sprintf(
                "%s,%s,%.4f,%s,%s,%s,%.3f\n",
                $query['id'],
                $query['type'],
                $query['duration'],
                str_replace('"', '""', substr($query['sql'], 0, 100)),
                json_encode($query['bindings']),
                $query['is_slow'] ? 'Yes' : 'No',
                $query['timestamp']
            );
        }
        
        return $csv;
    }

    /**
     * Export to HTML format
     */
    private function exportToHtml(array $data): string
    {
        $stats = $data['statistics'];
        
        $html = "
<!DOCTYPE html>
<html>
<head>
    <title>Query Analysis Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .summary { background: #f0f0f0; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .metric { display: inline-block; margin: 10px; padding: 10px; background: white; border-radius: 3px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .slow-query { background-color: #ffebee; }
        .issue { background: #fff3cd; padding: 10px; margin: 5px 0; border-radius: 3px; }
        .recommendation { background: #d4edda; padding: 10px; margin: 5px 0; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>Query Analysis Report</h1>
    
    <div class='summary'>
        <h2>Summary</h2>
        <div class='metric'>
            <strong>Total Queries:</strong> {$stats['total_queries']}
        </div>
        <div class='metric'>
            <strong>Slow Queries:</strong> {$stats['slow_queries']}
        </div>
        <div class='metric'>
            <strong>Duplicates:</strong> {$stats['duplicate_queries']}
        </div>
        <div class='metric'>
            <strong>Avg Duration:</strong> " . number_format($stats['average_duration'], 4) . "s
        </div>
    </div>";

        if (!empty($data['performance_issues'])) {
            $html .= "<h2>Performance Issues</h2>";
            foreach ($data['performance_issues'] as $issue) {
                $html .= "<div class='issue'><strong>{$issue['type']}:</strong> {$issue['message']}</div>";
            }
        }

        if (!empty($data['recommendations'])) {
            $html .= "<h2>Recommendations</h2>";
            foreach ($data['recommendations'] as $rec) {
                $html .= "<div class='recommendation'><strong>{$rec['title']}:</strong> {$rec['description']}</div>";
            }
        }

        $html .= "</body></html>";
        
        return $html;
    }
}