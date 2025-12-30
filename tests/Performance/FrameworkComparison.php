<?php

declare(strict_types=1);

namespace Tests\Performance;

/**
 * Framework Comparison Benchmark
 * 
 * Compares HybridPHP performance against Laravel, Symfony, and Swoole
 * using standardized test scenarios.
 */
class FrameworkComparison
{
    private array $results = [];
    private array $config;

    // Reference benchmarks from other frameworks (ops/sec)
    // These are approximate values from public benchmarks
    private array $referenceData = [
        'laravel' => [
            'routing' => 8500,
            'container' => 12000,
            'request_response' => 6000,
            'json_serialization' => 45000,
            'hello_world_qps' => 800,
        ],
        'symfony' => [
            'routing' => 15000,
            'container' => 18000,
            'request_response' => 8000,
            'json_serialization' => 48000,
            'hello_world_qps' => 1200,
        ],
        'swoole' => [
            'routing' => 85000,
            'container' => 95000,
            'request_response' => 75000,
            'json_serialization' => 120000,
            'hello_world_qps' => 150000,
        ],
        'workerman' => [
            'routing' => 80000,
            'container' => 90000,
            'request_response' => 70000,
            'json_serialization' => 110000,
            'hello_world_qps' => 140000,
        ],
    ];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'iterations' => 10000,
            'warmup' => 100,
        ], $config);
    }

    /**
     * Run comparison benchmarks
     */
    public function run(array $hybridResults): array
    {
        echo "\n📊 Framework Comparison Analysis\n";
        echo "================================\n\n";

        $comparison = [];

        foreach ($this->referenceData as $framework => $metrics) {
            $comparison[$framework] = [];
            
            foreach ($metrics as $metric => $refValue) {
                $hybridValue = $hybridResults[$metric]['ops_per_sec'] ?? null;
                
                if ($hybridValue !== null) {
                    $ratio = $hybridValue / $refValue;
                    $comparison[$framework][$metric] = [
                        'hybrid_ops' => $hybridValue,
                        'reference_ops' => $refValue,
                        'ratio' => $ratio,
                        'percentage' => ($ratio - 1) * 100,
                        'faster' => $ratio > 1,
                    ];
                }
            }
        }

        $this->results = $comparison;
        return $comparison;
    }

    /**
     * Generate comparison report
     */
    public function generateReport(): string
    {
        $report = "# Framework Performance Comparison\n\n";
        $report .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        $report .= "## Comparison Summary\n\n";
        $report .= "| Metric | HybridPHP | Laravel | Symfony | Swoole | Workerman |\n";
        $report .= "|--------|-----------|---------|---------|--------|----------|\n";

        $metrics = ['routing', 'container', 'request_response', 'json_serialization'];
        
        foreach ($metrics as $metric) {
            $hybridOps = $this->results['laravel'][$metric]['hybrid_ops'] ?? 'N/A';
            $laravelOps = $this->referenceData['laravel'][$metric] ?? 'N/A';
            $symfonyOps = $this->referenceData['symfony'][$metric] ?? 'N/A';
            $swooleOps = $this->referenceData['swoole'][$metric] ?? 'N/A';
            $workermanOps = $this->referenceData['workerman'][$metric] ?? 'N/A';

            $report .= sprintf(
                "| %s | %s | %s | %s | %s | %s |\n",
                $metric,
                is_numeric($hybridOps) ? number_format($hybridOps, 0) : $hybridOps,
                is_numeric($laravelOps) ? number_format($laravelOps, 0) : $laravelOps,
                is_numeric($symfonyOps) ? number_format($symfonyOps, 0) : $symfonyOps,
                is_numeric($swooleOps) ? number_format($swooleOps, 0) : $swooleOps,
                is_numeric($workermanOps) ? number_format($workermanOps, 0) : $workermanOps
            );
        }

        $report .= "\n## Performance Ratios (HybridPHP vs Others)\n\n";

        foreach ($this->results as $framework => $metrics) {
            $report .= "### vs {$framework}\n\n";
            $report .= "| Metric | HybridPHP | {$framework} | Ratio | Status |\n";
            $report .= "|--------|-----------|" . str_repeat('-', strlen($framework) + 2) . "|-------|--------|\n";

            foreach ($metrics as $metric => $data) {
                $status = $data['faster'] ? '✅ Faster' : '⚠️ Slower';
                $ratio = sprintf('%.2fx', $data['ratio']);
                
                $report .= sprintf(
                    "| %s | %s | %s | %s | %s |\n",
                    $metric,
                    number_format($data['hybrid_ops'], 0),
                    number_format($data['reference_ops'], 0),
                    $ratio,
                    $status
                );
            }
            $report .= "\n";
        }

        $report .= "## Analysis\n\n";
        $report .= $this->generateAnalysis();

        return $report;
    }

    /**
     * Generate performance analysis
     */
    private function generateAnalysis(): string
    {
        $analysis = "";

        // Calculate average performance vs traditional frameworks
        $vsLaravel = $this->calculateAverageRatio('laravel');
        $vsSymfony = $this->calculateAverageRatio('symfony');
        $vsSwoole = $this->calculateAverageRatio('swoole');
        $vsWorkerman = $this->calculateAverageRatio('workerman');

        $analysis .= "### Key Findings\n\n";

        if ($vsLaravel > 1) {
            $analysis .= sprintf("- **vs Laravel**: HybridPHP is %.1fx faster on average\n", $vsLaravel);
        } else {
            $analysis .= sprintf("- **vs Laravel**: HybridPHP is %.1fx slower on average\n", 1 / $vsLaravel);
        }

        if ($vsSymfony > 1) {
            $analysis .= sprintf("- **vs Symfony**: HybridPHP is %.1fx faster on average\n", $vsSymfony);
        } else {
            $analysis .= sprintf("- **vs Symfony**: HybridPHP is %.1fx slower on average\n", 1 / $vsSymfony);
        }

        if ($vsSwoole > 1) {
            $analysis .= sprintf("- **vs Swoole**: HybridPHP is %.1fx faster on average\n", $vsSwoole);
        } else {
            $analysis .= sprintf("- **vs Swoole**: HybridPHP is %.1fx slower on average (expected - Swoole is C extension)\n", 1 / $vsSwoole);
        }

        if ($vsWorkerman > 1) {
            $analysis .= sprintf("- **vs Workerman**: HybridPHP is %.1fx faster on average\n", $vsWorkerman);
        } else {
            $analysis .= sprintf("- **vs Workerman**: HybridPHP is %.1fx slower on average\n", 1 / $vsWorkerman);
        }

        $analysis .= "\n### Recommendations\n\n";
        $analysis .= "1. HybridPHP combines the ease of use of traditional frameworks with async performance\n";
        $analysis .= "2. For I/O-bound applications, async operations provide significant benefits\n";
        $analysis .= "3. Memory efficiency is optimized through connection pooling and caching\n";
        $analysis .= "4. Consider using HybridPHP for high-concurrency API services\n";

        return $analysis;
    }

    /**
     * Calculate average performance ratio
     */
    private function calculateAverageRatio(string $framework): float
    {
        if (!isset($this->results[$framework])) {
            return 1.0;
        }

        $ratios = array_column($this->results[$framework], 'ratio');
        return count($ratios) > 0 ? array_sum($ratios) / count($ratios) : 1.0;
    }

    /**
     * Export comparison data
     */
    public function exportJson(): string
    {
        return json_encode([
            'timestamp' => date('c'),
            'reference_data' => $this->referenceData,
            'comparison' => $this->results,
        ], JSON_PRETTY_PRINT);
    }
}
