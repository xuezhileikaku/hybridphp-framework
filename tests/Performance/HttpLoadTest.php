<?php

declare(strict_types=1);

namespace Tests\Performance;

use function Amp\async;
use function Amp\delay;

/**
 * HTTP Load Testing Tool
 * 
 * Simulates concurrent HTTP requests to measure server performance
 */
class HttpLoadTest
{
    private array $config;
    private array $results = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'base_url' => 'http://127.0.0.1:8080',
            'concurrent' => 100,
            'requests' => 10000,
            'timeout' => 30,
            'endpoints' => [
                ['method' => 'GET', 'path' => '/'],
                ['method' => 'GET', 'path' => '/api/health'],
                ['method' => 'GET', 'path' => '/api/users'],
                ['method' => 'POST', 'path' => '/api/users', 'body' => '{"name":"test"}'],
            ],
        ], $config);
    }

    /**
     * Run load test
     */
    public function run(): array
    {
        echo "🔥 HTTP Load Test\n";
        echo "=================\n\n";
        echo "Base URL: {$this->config['base_url']}\n";
        echo "Concurrent: {$this->config['concurrent']}\n";
        echo "Total Requests: {$this->config['requests']}\n\n";

        foreach ($this->config['endpoints'] as $endpoint) {
            $this->testEndpoint($endpoint);
        }

        return $this->results;
    }

    /**
     * Test a single endpoint
     */
    private function testEndpoint(array $endpoint): void
    {
        $method = $endpoint['method'];
        $path = $endpoint['path'];
        $body = $endpoint['body'] ?? null;
        $url = $this->config['base_url'] . $path;

        echo "Testing: {$method} {$path}...\n";

        $times = [];
        $errors = 0;
        $statusCodes = [];

        $startTime = microtime(true);

        // Run concurrent requests
        async(function () use ($method, $url, $body, &$times, &$errors, &$statusCodes) {
            $requestsPerWorker = (int) ceil($this->config['requests'] / $this->config['concurrent']);
            $futures = [];

            for ($i = 0; $i < $this->config['concurrent']; $i++) {
                $futures[] = async(function () use ($method, $url, $body, $requestsPerWorker, &$times, &$errors, &$statusCodes) {
                    for ($j = 0; $j < $requestsPerWorker; $j++) {
                        $result = $this->makeRequest($method, $url, $body);
                        
                        if ($result['success']) {
                            $times[] = $result['time'];
                            $statusCodes[$result['status']] = ($statusCodes[$result['status']] ?? 0) + 1;
                        } else {
                            $errors++;
                        }
                    }
                });
            }

            foreach ($futures as $future) {
                $future->await();
            }
        })->await();

        $totalTime = microtime(true) - $startTime;
        $totalRequests = count($times) + $errors;

        $this->results["{$method} {$path}"] = [
            'total_requests' => $totalRequests,
            'successful' => count($times),
            'errors' => $errors,
            'error_rate' => $errors / max($totalRequests, 1) * 100,
            'total_time_sec' => $totalTime,
            'qps' => $totalRequests / $totalTime,
            'avg_latency_ms' => count($times) > 0 ? array_sum($times) / count($times) : 0,
            'min_latency_ms' => count($times) > 0 ? min($times) : 0,
            'max_latency_ms' => count($times) > 0 ? max($times) : 0,
            'p50_latency_ms' => $this->percentile($times, 50),
            'p95_latency_ms' => $this->percentile($times, 95),
            'p99_latency_ms' => $this->percentile($times, 99),
            'status_codes' => $statusCodes,
        ];

        $qps = number_format($this->results["{$method} {$path}"]['qps'], 0);
        $avgLatency = number_format($this->results["{$method} {$path}"]['avg_latency_ms'], 2);
        echo "   ✅ QPS: {$qps}, Avg Latency: {$avgLatency}ms, Errors: {$errors}\n\n";
    }

    /**
     * Make HTTP request using cURL
     */
    private function makeRequest(string $method, string $url, ?string $body = null): array
    {
        $startTime = hrtime(true);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $endTime = hrtime(true);
        $timeMs = ($endTime - $startTime) / 1e6;

        return [
            'success' => $error === '' && $httpCode >= 200 && $httpCode < 500,
            'status' => $httpCode,
            'time' => $timeMs,
            'error' => $error,
        ];
    }

    /**
     * Calculate percentile
     */
    private function percentile(array $values, int $percentile): float
    {
        if (empty($values)) {
            return 0;
        }

        sort($values);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;
        return $values[max(0, $index)];
    }

    /**
     * Generate load test report
     */
    public function generateReport(): string
    {
        $report = "# HTTP Load Test Report\n\n";
        $report .= "Generated: " . date('Y-m-d H:i:s') . "\n";
        $report .= "Base URL: {$this->config['base_url']}\n";
        $report .= "Concurrent Connections: {$this->config['concurrent']}\n";
        $report .= "Total Requests per Endpoint: {$this->config['requests']}\n\n";

        $report .= "## Results Summary\n\n";
        $report .= "| Endpoint | QPS | Avg Latency | P95 | P99 | Error Rate |\n";
        $report .= "|----------|-----|-------------|-----|-----|------------|\n";

        foreach ($this->results as $endpoint => $result) {
            $report .= sprintf(
                "| %s | %s | %.2fms | %.2fms | %.2fms | %.2f%% |\n",
                $endpoint,
                number_format($result['qps'], 0),
                $result['avg_latency_ms'],
                $result['p95_latency_ms'],
                $result['p99_latency_ms'],
                $result['error_rate']
            );
        }

        $report .= "\n## Detailed Results\n\n";

        foreach ($this->results as $endpoint => $result) {
            $report .= "### {$endpoint}\n\n";
            $report .= "```\n";
            $report .= "Total Requests:    {$result['total_requests']}\n";
            $report .= "Successful:        {$result['successful']}\n";
            $report .= "Errors:            {$result['errors']}\n";
            $report .= "Error Rate:        " . number_format($result['error_rate'], 2) . "%\n";
            $report .= "Total Time:        " . number_format($result['total_time_sec'], 2) . "s\n";
            $report .= "QPS:               " . number_format($result['qps'], 0) . "\n";
            $report .= "Avg Latency:       " . number_format($result['avg_latency_ms'], 2) . "ms\n";
            $report .= "Min Latency:       " . number_format($result['min_latency_ms'], 2) . "ms\n";
            $report .= "Max Latency:       " . number_format($result['max_latency_ms'], 2) . "ms\n";
            $report .= "P50 Latency:       " . number_format($result['p50_latency_ms'], 2) . "ms\n";
            $report .= "P95 Latency:       " . number_format($result['p95_latency_ms'], 2) . "ms\n";
            $report .= "P99 Latency:       " . number_format($result['p99_latency_ms'], 2) . "ms\n";
            $report .= "```\n\n";

            if (!empty($result['status_codes'])) {
                $report .= "Status Codes:\n";
                foreach ($result['status_codes'] as $code => $count) {
                    $report .= "- {$code}: {$count}\n";
                }
                $report .= "\n";
            }
        }

        return $report;
    }

    /**
     * Export results to JSON
     */
    public function exportJson(): string
    {
        return json_encode([
            'timestamp' => date('c'),
            'config' => $this->config,
            'results' => $this->results,
        ], JSON_PRETTY_PRINT);
    }
}
