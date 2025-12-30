<?php

declare(strict_types=1);

/**
 * HybridPHP HTTP Load Test Runner
 * 
 * Usage:
 *   php scripts/run-load-test.php [options]
 * 
 * Options:
 *   --url=URL         Base URL to test (default: http://127.0.0.1:8080)
 *   --concurrent=N    Concurrent connections (default: 100)
 *   --requests=N      Total requests per endpoint (default: 10000)
 *   --timeout=N       Request timeout in seconds (default: 30)
 *   --output=DIR      Output directory for reports
 *   --help            Show this help
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Tests\Performance\HttpLoadTest;

// Parse command line arguments
$options = getopt('', [
    'url:',
    'concurrent:',
    'requests:',
    'timeout:',
    'output:',
    'help',
]);

if (isset($options['help'])) {
    echo <<<HELP
HybridPHP HTTP Load Test Runner

Usage:
  php scripts/run-load-test.php [options]

Options:
  --url=URL         Base URL to test (default: http://127.0.0.1:8080)
  --concurrent=N    Concurrent connections (default: 100)
  --requests=N      Total requests per endpoint (default: 10000)
  --timeout=N       Request timeout in seconds (default: 30)
  --output=DIR      Output directory for reports
  --help            Show this help

Examples:
  php scripts/run-load-test.php --url=http://localhost:8080
  php scripts/run-load-test.php --concurrent=200 --requests=50000
  php scripts/run-load-test.php --url=http://api.example.com --output=./reports

Note: Make sure the server is running before starting the load test.

HELP;
    exit(0);
}

// Configuration
$config = [
    'base_url' => $options['url'] ?? 'http://127.0.0.1:8080',
    'concurrent' => (int) ($options['concurrent'] ?? 100),
    'requests' => (int) ($options['requests'] ?? 10000),
    'timeout' => (int) ($options['timeout'] ?? 30),
];

$outputDir = $options['output'] ?? __DIR__ . '/../storage/benchmarks';

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║            HybridPHP HTTP Load Test                          ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

echo "Configuration:\n";
echo "  Base URL: {$config['base_url']}\n";
echo "  Concurrent: {$config['concurrent']}\n";
echo "  Requests: {$config['requests']}\n";
echo "  Timeout: {$config['timeout']}s\n";
echo "  Output: {$outputDir}\n\n";

// Check if server is reachable
echo "Checking server connectivity...\n";
$ch = curl_init($config['base_url']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error || $httpCode === 0) {
    echo "❌ Cannot connect to {$config['base_url']}\n";
    echo "   Error: {$error}\n";
    echo "\nPlease make sure the server is running:\n";
    echo "  php bin/hybridphp serve\n";
    exit(1);
}

echo "✅ Server is reachable (HTTP {$httpCode})\n\n";

// Create output directory
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Run load test
$loadTest = new HttpLoadTest($config);
$results = $loadTest->run();

// Save reports
$timestamp = date('Y-m-d_H-i-s');
$reportPath = "{$outputDir}/loadtest_{$timestamp}.md";
$jsonPath = "{$outputDir}/loadtest_{$timestamp}.json";

file_put_contents($reportPath, $loadTest->generateReport());
file_put_contents($jsonPath, $loadTest->exportJson());

echo "\n📁 Load test report saved to: {$reportPath}\n";

// Print summary
echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                  Load Test Summary                           ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

echo "| Endpoint                    | QPS      | Avg Latency | P99      |\n";
echo "|-----------------------------|----------|-------------|----------|\n";

foreach ($results as $endpoint => $result) {
    printf(
        "| %-27s | %8s | %11.2fms | %8.2fms |\n",
        substr($endpoint, 0, 27),
        number_format($result['qps'], 0),
        $result['avg_latency_ms'],
        $result['p99_latency_ms']
    );
}

// Calculate totals
$totalQps = array_sum(array_column($results, 'qps'));
$avgLatency = array_sum(array_column($results, 'avg_latency_ms')) / count($results);
$totalErrors = array_sum(array_column($results, 'errors'));

echo "\n";
echo "Total QPS: " . number_format($totalQps, 0) . "\n";
echo "Average Latency: " . number_format($avgLatency, 2) . "ms\n";
echo "Total Errors: {$totalErrors}\n";

echo "\n✅ Load test completed successfully!\n";
