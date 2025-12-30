<?php

declare(strict_types=1);

namespace Tests\Performance;

/**
 * Performance Bottleneck Analyzer
 * 
 * Identifies performance bottlenecks and provides optimization recommendations
 */
class BottleneckAnalyzer
{
    private array $benchmarkResults;
    private array $thresholds;
    private array $bottlenecks = [];
    private array $recommendations = [];

    public function __construct(array $benchmarkResults, array $thresholds = [])
    {
        $this->benchmarkResults = $benchmarkResults;
        $this->thresholds = array_merge([
            'routing_ops_min' => 50000,
            'container_ops_min' => 80000,
            'cache_ops_min' => 100000,
            'request_response_ops_min' => 40000,
            'async_ops_min' => 20000,
            'memory_max_mb' => 50,
            'p99_max_ms' => 10,
        ], $thresholds);
    }

    /**
     * Analyze benchmark results for bottlenecks
     */
    public function analyze(): array
    {
        echo "\n🔍 Bottleneck Analysis\n";
        echo "======================\n\n";

        $this->analyzeRouting();
        $this->analyzeContainer();
        $this->analyzeCache();
        $this->analyzeRequestResponse();
        $this->analyzeAsyncOperations();
        $this->analyzeMemory();
        $this->analyzeLatency();

        return [
            'bottlenecks' => $this->bottlenecks,
            'recommendations' => $this->recommendations,
            'score' => $this->calculateScore(),
        ];
    }

    /**
     * Analyze routing performance
     */
    private function analyzeRouting(): void
    {
        $result = $this->benchmarkResults['routing'] ?? null;
        if (!$result) return;

        $ops = $result['ops_per_sec'];
        $threshold = $this->thresholds['routing_ops_min'];

        if ($ops < $threshold) {
            $this->bottlenecks[] = [
                'component' => 'routing',
                'severity' => $this->calculateSeverity($ops, $threshold),
                'current' => $ops,
                'expected' => $threshold,
                'message' => "Routing performance ({$ops} ops/sec) is below threshold ({$threshold} ops/sec)",
            ];

            $this->recommendations[] = [
                'component' => 'routing',
                'priority' => 'high',
                'suggestions' => [
                    'Enable route caching in production',
                    'Use route groups to reduce matching complexity',
                    'Consider using compiled routes',
                    'Reduce the number of regex-based route parameters',
                ],
            ];
        } else {
            echo "✅ Routing: {$ops} ops/sec (threshold: {$threshold})\n";
        }
    }

    /**
     * Analyze container/DI performance
     */
    private function analyzeContainer(): void
    {
        $result = $this->benchmarkResults['container'] ?? null;
        if (!$result) return;

        $ops = $result['ops_per_sec'];
        $threshold = $this->thresholds['container_ops_min'];

        if ($ops < $threshold) {
            $this->bottlenecks[] = [
                'component' => 'container',
                'severity' => $this->calculateSeverity($ops, $threshold),
                'current' => $ops,
                'expected' => $threshold,
                'message' => "Container performance ({$ops} ops/sec) is below threshold ({$threshold} ops/sec)",
            ];

            $this->recommendations[] = [
                'component' => 'container',
                'priority' => 'high',
                'suggestions' => [
                    'Use singleton bindings for frequently accessed services',
                    'Enable container compilation in production',
                    'Reduce deep dependency chains',
                    'Consider lazy loading for heavy services',
                ],
            ];
        } else {
            echo "✅ Container: {$ops} ops/sec (threshold: {$threshold})\n";
        }
    }

    /**
     * Analyze cache performance
     */
    private function analyzeCache(): void
    {
        $result = $this->benchmarkResults['cache'] ?? null;
        if (!$result) return;

        $ops = $result['ops_per_sec'];
        $threshold = $this->thresholds['cache_ops_min'];

        if ($ops < $threshold) {
            $this->bottlenecks[] = [
                'component' => 'cache',
                'severity' => $this->calculateSeverity($ops, $threshold),
                'current' => $ops,
                'expected' => $threshold,
                'message' => "Cache performance ({$ops} ops/sec) is below threshold ({$threshold} ops/sec)",
            ];

            $this->recommendations[] = [
                'component' => 'cache',
                'priority' => 'medium',
                'suggestions' => [
                    'Use memory cache for hot data',
                    'Implement multi-level caching (L1: memory, L2: Redis)',
                    'Optimize serialization (use igbinary or msgpack)',
                    'Batch cache operations where possible',
                ],
            ];
        } else {
            echo "✅ Cache: {$ops} ops/sec (threshold: {$threshold})\n";
        }
    }

    /**
     * Analyze request/response handling
     */
    private function analyzeRequestResponse(): void
    {
        $result = $this->benchmarkResults['request_response'] ?? null;
        if (!$result) return;

        $ops = $result['ops_per_sec'];
        $threshold = $this->thresholds['request_response_ops_min'];

        if ($ops < $threshold) {
            $this->bottlenecks[] = [
                'component' => 'request_response',
                'severity' => $this->calculateSeverity($ops, $threshold),
                'current' => $ops,
                'expected' => $threshold,
                'message' => "Request/Response handling ({$ops} ops/sec) is below threshold ({$threshold} ops/sec)",
            ];

            $this->recommendations[] = [
                'component' => 'request_response',
                'priority' => 'high',
                'suggestions' => [
                    'Use streaming for large responses',
                    'Enable response compression (gzip/brotli)',
                    'Minimize header processing',
                    'Use object pooling for request/response objects',
                ],
            ];
        } else {
            echo "✅ Request/Response: {$ops} ops/sec (threshold: {$threshold})\n";
        }
    }

    /**
     * Analyze async operations
     */
    private function analyzeAsyncOperations(): void
    {
        $result = $this->benchmarkResults['async_operations'] ?? null;
        if (!$result) return;

        $ops = $result['ops_per_sec'];
        $threshold = $this->thresholds['async_ops_min'];

        if ($ops < $threshold) {
            $this->bottlenecks[] = [
                'component' => 'async_operations',
                'severity' => $this->calculateSeverity($ops, $threshold),
                'current' => $ops,
                'expected' => $threshold,
                'message' => "Async operations ({$ops} ops/sec) is below threshold ({$threshold} ops/sec)",
            ];

            $this->recommendations[] = [
                'component' => 'async_operations',
                'priority' => 'medium',
                'suggestions' => [
                    'Use Amp\\Future::await() instead of blocking calls',
                    'Batch async operations with Amp\\async()',
                    'Avoid creating too many concurrent futures',
                    'Use connection pooling for I/O operations',
                ],
            ];
        } else {
            echo "✅ Async Operations: {$ops} ops/sec (threshold: {$threshold})\n";
        }
    }

    /**
     * Analyze memory usage
     */
    private function analyzeMemory(): void
    {
        $maxMemory = 0;
        foreach ($this->benchmarkResults as $result) {
            $maxMemory = max($maxMemory, $result['memory_peak_mb'] ?? 0);
        }

        $threshold = $this->thresholds['memory_max_mb'];

        if ($maxMemory > $threshold) {
            $this->bottlenecks[] = [
                'component' => 'memory',
                'severity' => $this->calculateSeverity($threshold, $maxMemory),
                'current' => $maxMemory,
                'expected' => $threshold,
                'message' => "Memory usage ({$maxMemory} MB) exceeds threshold ({$threshold} MB)",
            ];

            $this->recommendations[] = [
                'component' => 'memory',
                'priority' => 'high',
                'suggestions' => [
                    'Use generators for large data sets',
                    'Implement object pooling',
                    'Clear unused references promptly',
                    'Use weak references where appropriate',
                    'Profile memory with Xdebug or Blackfire',
                ],
            ];
        } else {
            echo "✅ Memory: {$maxMemory} MB (threshold: {$threshold} MB)\n";
        }
    }

    /**
     * Analyze latency (P99)
     */
    private function analyzeLatency(): void
    {
        $maxP99 = 0;
        $worstComponent = '';

        foreach ($this->benchmarkResults as $name => $result) {
            $p99 = $result['p99_time_ms'] ?? 0;
            if ($p99 > $maxP99) {
                $maxP99 = $p99;
                $worstComponent = $name;
            }
        }

        $threshold = $this->thresholds['p99_max_ms'];

        if ($maxP99 > $threshold) {
            $this->bottlenecks[] = [
                'component' => 'latency',
                'severity' => $this->calculateSeverity($threshold, $maxP99),
                'current' => $maxP99,
                'expected' => $threshold,
                'message' => "P99 latency ({$maxP99}ms in {$worstComponent}) exceeds threshold ({$threshold}ms)",
            ];

            $this->recommendations[] = [
                'component' => 'latency',
                'priority' => 'high',
                'suggestions' => [
                    "Optimize {$worstComponent} component",
                    'Add caching for expensive operations',
                    'Use async I/O to avoid blocking',
                    'Profile with flame graphs to identify hot paths',
                    'Consider using JIT compilation (PHP 8.0+)',
                ],
            ];
        } else {
            echo "✅ P99 Latency: {$maxP99}ms (threshold: {$threshold}ms)\n";
        }
    }

    /**
     * Calculate severity level
     */
    private function calculateSeverity(float $current, float $expected): string
    {
        $ratio = $current / $expected;

        if ($ratio < 0.5) return 'critical';
        if ($ratio < 0.7) return 'high';
        if ($ratio < 0.9) return 'medium';
        return 'low';
    }

    /**
     * Calculate overall performance score
     */
    private function calculateScore(): int
    {
        $score = 100;

        foreach ($this->bottlenecks as $bottleneck) {
            switch ($bottleneck['severity']) {
                case 'critical':
                    $score -= 25;
                    break;
                case 'high':
                    $score -= 15;
                    break;
                case 'medium':
                    $score -= 10;
                    break;
                case 'low':
                    $score -= 5;
                    break;
            }
        }

        return max(0, $score);
    }

    /**
     * Generate analysis report
     */
    public function generateReport(): string
    {
        $analysis = $this->analyze();

        $report = "# Performance Bottleneck Analysis Report\n\n";
        $report .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";

        $report .= "## Overall Score: {$analysis['score']}/100\n\n";

        $scoreEmoji = $analysis['score'] >= 80 ? '🟢' : ($analysis['score'] >= 60 ? '🟡' : '🔴');
        $report .= "{$scoreEmoji} ";
        
        if ($analysis['score'] >= 80) {
            $report .= "Excellent performance! Minor optimizations may still be possible.\n\n";
        } elseif ($analysis['score'] >= 60) {
            $report .= "Good performance with room for improvement.\n\n";
        } else {
            $report .= "Performance issues detected. Review recommendations below.\n\n";
        }

        if (!empty($analysis['bottlenecks'])) {
            $report .= "## Identified Bottlenecks\n\n";
            $report .= "| Component | Severity | Current | Expected | Issue |\n";
            $report .= "|-----------|----------|---------|----------|-------|\n";

            foreach ($analysis['bottlenecks'] as $bottleneck) {
                $severityEmoji = match($bottleneck['severity']) {
                    'critical' => '🔴',
                    'high' => '🟠',
                    'medium' => '🟡',
                    default => '🟢',
                };

                $report .= sprintf(
                    "| %s | %s %s | %s | %s | %s |\n",
                    $bottleneck['component'],
                    $severityEmoji,
                    $bottleneck['severity'],
                    is_numeric($bottleneck['current']) ? number_format($bottleneck['current'], 2) : $bottleneck['current'],
                    is_numeric($bottleneck['expected']) ? number_format($bottleneck['expected'], 2) : $bottleneck['expected'],
                    $bottleneck['message']
                );
            }
            $report .= "\n";
        }

        if (!empty($analysis['recommendations'])) {
            $report .= "## Optimization Recommendations\n\n";

            foreach ($analysis['recommendations'] as $rec) {
                $priorityEmoji = match($rec['priority']) {
                    'high' => '🔴',
                    'medium' => '🟡',
                    default => '🟢',
                };

                $report .= "### {$rec['component']} ({$priorityEmoji} {$rec['priority']} priority)\n\n";
                
                foreach ($rec['suggestions'] as $suggestion) {
                    $report .= "- {$suggestion}\n";
                }
                $report .= "\n";
            }
        }

        $report .= "## Next Steps\n\n";
        $report .= "1. Address critical and high-priority bottlenecks first\n";
        $report .= "2. Re-run benchmarks after each optimization\n";
        $report .= "3. Monitor production metrics to validate improvements\n";
        $report .= "4. Consider profiling with Xdebug or Blackfire for detailed analysis\n";

        return $report;
    }

    /**
     * Export analysis to JSON
     */
    public function exportJson(): string
    {
        return json_encode([
            'timestamp' => date('c'),
            'analysis' => $this->analyze(),
            'thresholds' => $this->thresholds,
        ], JSON_PRETTY_PRINT);
    }
}
