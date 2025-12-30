<?php

declare(strict_types=1);

/**
 * HybridPHP Performance Benchmark Runner
 * 
 * Usage:
 *   php scripts/run-benchmarks.php [options]
 * 
 * Options:
 *   --iterations=N    Number of iterations (default: 10000)
 *   --warmup=N        Warmup iterations (default: 100)
 *   --output=DIR      Output directory for reports
 *   --compare         Run framework comparison
 *   --analyze         Run bottleneck analysis
 *   --all             Run all benchmarks and analysis
 *   --help            Show this help
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Tests\Performance\BenchmarkSuite;
use Tests\Performance\FrameworkComparison;
use Tests\Performance\BottleneckAnalyzer;

// Parse command line arguments
$options = getopt('', [
    'iterations:',
    'warmup:',
    'output:',
    'compare',
    'analyze',
    'all',
    'help',
]);

if (isset($options['help'])) {
    echo <<<HELP
HybridPHP Performance Benchmark Runner

Usage:
  php scripts/run-benchmarks.php [options]

Options:
  --iterations=N    Number of iterations (default: 10000)
  --warmup=N        Warmup iterations (default: 100)
  --output=DIR      Output directory for reports
  --compare         Run framework comparison
  --analyze         Run bottleneck analysis
  --all             Run all benchmarks and analysis
  --help            Show this help

Examples:
  php scripts/run-benchmarks.php --all
  php scripts/run-benchmarks.php --iterations=5000 --compare
  php scripts/run-benchmarks.php --analyze --output=./reports

HELP;
    exit(0);
}

// Configuration
$config = [
    'iterations' => (int) ($options['iterations'] ?? 10000),
    'warmup' => (int) ($options['warmup'] ?? 100),
    'output_dir' => $options['output'] ?? __DIR__ . '/../storage/benchmarks',
];

$runCompare = isset($options['compare']) || isset($options['all']);
$runAnalyze = isset($options['analyze']) || isset($options['all']);

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         HybridPHP Performance Benchmark Suite                ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

echo "Configuration:\n";
echo "  Iterations: {$config['iterations']}\n";
echo "  Warmup: {$config['warmup']}\n";
echo "  Output: {$config['output_dir']}\n\n";

// Create output directory
if (!is_dir($config['output_dir'])) {
    mkdir($config['output_dir'], 0755, true);
}

// Run benchmarks
$suite = new BenchmarkSuite($config);
$results = $suite->runAll();

// Save benchmark report
$timestamp = date('Y-m-d_H-i-s');
$reportPath = "{$config['output_dir']}/benchmark_{$timestamp}.md";
$jsonPath = "{$config['output_dir']}/benchmark_{$timestamp}.json";

file_put_contents($reportPath, $suite->generateReport());
file_put_contents($jsonPath, $suite->exportJson());

echo "\n📁 Benchmark report saved to: {$reportPath}\n";

// Run framework comparison
if ($runCompare) {
    echo "\n";
    $comparison = new FrameworkComparison();
    $comparisonResults = $comparison->run($results);
    
    $comparisonPath = "{$config['output_dir']}/comparison_{$timestamp}.md";
    file_put_contents($comparisonPath, $comparison->generateReport());
    file_put_contents("{$config['output_dir']}/comparison_{$timestamp}.json", $comparison->exportJson());
    
    echo "\n📁 Comparison report saved to: {$comparisonPath}\n";
}

// Run bottleneck analysis
if ($runAnalyze) {
    echo "\n";
    $analyzer = new BottleneckAnalyzer($results);
    
    $analysisPath = "{$config['output_dir']}/analysis_{$timestamp}.md";
    file_put_contents($analysisPath, $analyzer->generateReport());
    file_put_contents("{$config['output_dir']}/analysis_{$timestamp}.json", $analyzer->exportJson());
    
    echo "\n📁 Analysis report saved to: {$analysisPath}\n";
}

// Print summary
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                    Benchmark Summary                         ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

echo "| Benchmark            | Ops/sec      | Avg (ms)  | P99 (ms)  |\n";
echo "|----------------------|--------------|-----------|----------|\n";

foreach ($results as $name => $result) {
    printf(
        "| %-20s | %12s | %9.4f | %9.4f |\n",
        substr($name, 0, 20),
        number_format($result['ops_per_sec'], 0),
        $result['avg_time_ms'],
        $result['p99_time_ms']
    );
}

echo "\n✅ Benchmark completed successfully!\n";
