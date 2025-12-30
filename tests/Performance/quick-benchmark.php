<?php

declare(strict_types=1);

/**
 * Quick benchmark test to verify the benchmark suite works
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Tests\Performance\BenchmarkSuite;
use Tests\Performance\FrameworkComparison;
use Tests\Performance\BottleneckAnalyzer;

echo "🧪 Quick Benchmark Test\n";
echo "========================\n\n";

// Run with fewer iterations for quick testing
$config = [
    'iterations' => 1000,
    'warmup' => 10,
    'output_dir' => __DIR__ . '/../../storage/benchmarks',
];

try {
    $suite = new BenchmarkSuite($config);
    $results = $suite->runAll();

    echo "\n📊 Results Summary:\n";
    foreach ($results as $name => $result) {
        printf(
            "  %s: %s ops/sec (avg: %.4fms)\n",
            $name,
            number_format($result['ops_per_sec'], 0),
            $result['avg_time_ms']
        );
    }

    // Run comparison
    echo "\n📈 Framework Comparison:\n";
    $comparison = new FrameworkComparison();
    $comparisonResults = $comparison->run($results);

    // Run analysis
    echo "\n🔍 Bottleneck Analysis:\n";
    $analyzer = new BottleneckAnalyzer($results);
    $analysis = $analyzer->analyze();
    
    echo "\n✅ Overall Score: {$analysis['score']}/100\n";

    if (!empty($analysis['bottlenecks'])) {
        echo "\n⚠️ Bottlenecks found:\n";
        foreach ($analysis['bottlenecks'] as $bottleneck) {
            echo "  - [{$bottleneck['severity']}] {$bottleneck['component']}: {$bottleneck['message']}\n";
        }
    }

    echo "\n✅ Quick benchmark test completed successfully!\n";

} catch (\Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
