<?php

declare(strict_types=1);

namespace Tests\Performance;

use HybridPHP\Core\Application;
use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use HybridPHP\Core\Routing\Router;
use HybridPHP\Core\Cache\MemoryCache;
use function Amp\async;
use function Amp\delay;

/**
 * HybridPHP Performance Benchmark Suite
 * 
 * Comprehensive benchmarking tool for measuring framework performance
 * and comparing with Laravel, Symfony, and Swoole.
 */
class BenchmarkSuite
{
    private array $results = [];
    private array $config;
    private float $startTime;
    private int $memoryStart;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'iterations' => 10000,
            'warmup' => 100,
            'concurrent' => 100,
            'output_dir' => __DIR__ . '/../../storage/benchmarks',
        ], $config);
    }

    /**
     * Run all benchmarks
     */
    public function runAll(): array
    {
        echo "🚀 HybridPHP Performance Benchmark Suite\n";
        echo "=========================================\n\n";

        $this->runBenchmark('routing', [$this, 'benchmarkRouting']);
        $this->runBenchmark('container', [$this, 'benchmarkContainer']);
        $this->runBenchmark('request_response', [$this, 'benchmarkRequestResponse']);
        $this->runBenchmark('cache', [$this, 'benchmarkCache']);
        $this->runBenchmark('async_operations', [$this, 'benchmarkAsyncOperations']);
        $this->runBenchmark('middleware_pipeline', [$this, 'benchmarkMiddlewarePipeline']);
        $this->runBenchmark('json_serialization', [$this, 'benchmarkJsonSerialization']);
        $this->runBenchmark('memory_usage', [$this, 'benchmarkMemoryUsage']);

        return $this->results;
    }

    /**
     * Run a single benchmark
     */
    private function runBenchmark(string $name, callable $benchmark): void
    {
        echo "📊 Running: {$name}...\n";

        // Warmup
        for ($i = 0; $i < $this->config['warmup']; $i++) {
            $benchmark();
        }

        // Actual benchmark
        $times = [];
        $memoryUsages = [];

        gc_collect_cycles();
        $this->memoryStart = memory_get_usage(true);
        $this->startTime = microtime(true);

        for ($i = 0; $i < $this->config['iterations']; $i++) {
            $iterStart = hrtime(true);
            $benchmark();
            $iterEnd = hrtime(true);
            $times[] = ($iterEnd - $iterStart) / 1e6; // Convert to milliseconds
            
            if ($i % 1000 === 0) {
                $memoryUsages[] = memory_get_usage(true) - $this->memoryStart;
            }
        }

        $totalTime = microtime(true) - $this->startTime;
        $memoryPeak = memory_get_peak_usage(true) - $this->memoryStart;

        $this->results[$name] = [
            'iterations' => $this->config['iterations'],
            'total_time_ms' => $totalTime * 1000,
            'avg_time_ms' => array_sum($times) / count($times),
            'min_time_ms' => min($times),
            'max_time_ms' => max($times),
            'median_time_ms' => $this->median($times),
            'p95_time_ms' => $this->percentile($times, 95),
            'p99_time_ms' => $this->percentile($times, 99),
            'ops_per_sec' => $this->config['iterations'] / $totalTime,
            'memory_peak_bytes' => $memoryPeak,
            'memory_peak_mb' => round($memoryPeak / 1024 / 1024, 2),
        ];

        $ops = number_format($this->results[$name]['ops_per_sec'], 0);
        $avg = number_format($this->results[$name]['avg_time_ms'], 4);
        echo "   ✅ {$ops} ops/sec, avg: {$avg}ms\n\n";
    }

    /**
     * Benchmark routing performance
     */
    private function benchmarkRouting(): void
    {
        static $router = null;
        
        if ($router === null) {
            $router = new Router();
            
            // Register various routes
            $router->get('/', fn() => 'home');
            $router->get('/users', fn() => 'users.index');
            $router->get('/users/{id}', fn() => 'users.show');
            $router->post('/users', fn() => 'users.store');
            $router->put('/users/{id}', fn() => 'users.update');
            $router->delete('/users/{id}', fn() => 'users.delete');
            $router->get('/posts/{post}/comments/{comment}', fn() => 'comments.show');
            
            // Add more routes for realistic testing
            for ($i = 0; $i < 100; $i++) {
                $router->get("/api/v1/resource{$i}", fn() => "resource{$i}");
                $router->get("/api/v1/resource{$i}/{id}", fn() => "resource{$i}.show");
            }
        }

        // Dispatch various routes
        $router->dispatch('GET', '/users/123');
        $router->dispatch('POST', '/users');
        $router->dispatch('GET', '/posts/1/comments/5');
    }

    /**
     * Benchmark container/DI performance
     */
    private function benchmarkContainer(): void
    {
        static $container = null;
        
        if ($container === null) {
            $container = new \HybridPHP\Core\Container();
            
            // Register services
            $container->bind('service.a', fn() => new \stdClass());
            $container->singleton('service.b', fn() => new \stdClass());
            $container->bind('service.c', fn($c) => (object)['dep' => $c->get('service.b')]);
        }

        // Resolve services
        $container->get('service.a');
        $container->get('service.b');
        $container->get('service.c');
    }

    /**
     * Benchmark request/response handling
     */
    private function benchmarkRequestResponse(): void
    {
        $uri = new \HybridPHP\Core\Http\Uri('http://localhost/api/users?page=1&limit=20');
        $request = new Request('GET', $uri, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer token123',
        ]);

        // Access request data
        $request->getMethod();
        $request->getUri()->getPath();
        $request->getQueryParams();
        $request->getHeaderLine('Authorization');

        // Create response
        $response = new Response(200, [
            'Content-Type' => 'application/json',
            'X-Request-Id' => 'req-123',
        ], json_encode(['status' => 'ok', 'data' => range(1, 10)]));

        $response->getStatusCode();
        $response->getHeaders();
        $response->getBody()->getContents();
    }

    /**
     * Benchmark cache operations
     */
    private function benchmarkCache(): void
    {
        static $cache = null;
        static $key = 'benchmark_key';
        
        if ($cache === null) {
            $cache = new MemoryCache(['prefix' => 'bench_']);
        }

        async(function () use ($cache, $key) {
            // Set
            $cache->set($key, ['data' => 'value', 'timestamp' => time()])->await();
            
            // Get
            $cache->get($key)->await();
            
            // Has
            $cache->has($key)->await();
            
            // Delete
            $cache->delete($key)->await();
        })->await();
    }

    /**
     * Benchmark async operations
     */
    private function benchmarkAsyncOperations(): void
    {
        async(function () {
            // Simulate concurrent async operations
            $futures = [];
            
            for ($i = 0; $i < 10; $i++) {
                $futures[] = async(function () use ($i) {
                    // Simulate async work
                    return $i * 2;
                });
            }

            // Await all
            foreach ($futures as $future) {
                $future->await();
            }
        })->await();
    }

    /**
     * Benchmark middleware pipeline
     */
    private function benchmarkMiddlewarePipeline(): void
    {
        static $pipeline = null;
        
        if ($pipeline === null) {
            $handler = new BenchmarkHandler();
            $pipeline = new \HybridPHP\Core\MiddlewarePipeline($handler);
            
            // Add middleware
            $pipeline->through(new BenchmarkMiddleware('auth'));
            $pipeline->through(new BenchmarkMiddleware('cors'));
            $pipeline->through(new BenchmarkMiddleware('logging'));
        }

        $uri = new \HybridPHP\Core\Http\Uri('http://localhost/test');
        $request = new Request('GET', $uri);
        $pipeline->handle($request);
    }

    /**
     * Benchmark JSON serialization
     */
    private function benchmarkJsonSerialization(): void
    {
        $data = [
            'users' => array_map(fn($i) => [
                'id' => $i,
                'name' => "User {$i}",
                'email' => "user{$i}@example.com",
                'created_at' => date('Y-m-d H:i:s'),
                'metadata' => ['key' => 'value', 'nested' => ['a' => 1, 'b' => 2]],
            ], range(1, 50)),
            'pagination' => [
                'page' => 1,
                'per_page' => 50,
                'total' => 1000,
            ],
        ];

        // Encode
        $json = json_encode($data);
        
        // Decode
        json_decode($json, true);
    }

    /**
     * Benchmark memory usage patterns
     */
    private function benchmarkMemoryUsage(): void
    {
        // Create and destroy objects to test memory management
        $objects = [];
        
        for ($i = 0; $i < 100; $i++) {
            $objects[] = new \stdClass();
            $objects[$i]->data = str_repeat('x', 100);
        }

        // Clear
        $objects = [];
    }

    /**
     * Calculate median
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }

    /**
     * Calculate percentile
     */
    private function percentile(array $values, int $percentile): float
    {
        sort($values);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;
        return $values[max(0, $index)];
    }

    /**
     * Generate performance report
     */
    public function generateReport(): string
    {
        $report = "# HybridPHP Performance Benchmark Report\n\n";
        $report .= "Generated: " . date('Y-m-d H:i:s') . "\n";
        $report .= "PHP Version: " . PHP_VERSION . "\n";
        $report .= "Iterations: " . $this->config['iterations'] . "\n\n";

        $report .= "## Summary\n\n";
        $report .= "| Benchmark | Ops/sec | Avg (ms) | P95 (ms) | P99 (ms) | Memory (MB) |\n";
        $report .= "|-----------|---------|----------|----------|----------|-------------|\n";

        foreach ($this->results as $name => $result) {
            $report .= sprintf(
                "| %s | %s | %.4f | %.4f | %.4f | %.2f |\n",
                $name,
                number_format($result['ops_per_sec'], 0),
                $result['avg_time_ms'],
                $result['p95_time_ms'],
                $result['p99_time_ms'],
                $result['memory_peak_mb']
            );
        }

        $report .= "\n## Detailed Results\n\n";

        foreach ($this->results as $name => $result) {
            $report .= "### {$name}\n\n";
            $report .= "```\n";
            $report .= "Iterations:     {$result['iterations']}\n";
            $report .= "Total Time:     " . number_format($result['total_time_ms'], 2) . " ms\n";
            $report .= "Ops/sec:        " . number_format($result['ops_per_sec'], 0) . "\n";
            $report .= "Avg Time:       " . number_format($result['avg_time_ms'], 4) . " ms\n";
            $report .= "Min Time:       " . number_format($result['min_time_ms'], 4) . " ms\n";
            $report .= "Max Time:       " . number_format($result['max_time_ms'], 4) . " ms\n";
            $report .= "Median Time:    " . number_format($result['median_time_ms'], 4) . " ms\n";
            $report .= "P95 Time:       " . number_format($result['p95_time_ms'], 4) . " ms\n";
            $report .= "P99 Time:       " . number_format($result['p99_time_ms'], 4) . " ms\n";
            $report .= "Memory Peak:    " . number_format($result['memory_peak_mb'], 2) . " MB\n";
            $report .= "```\n\n";
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
            'php_version' => PHP_VERSION,
            'config' => $this->config,
            'results' => $this->results,
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Save report to file
     */
    public function saveReport(): void
    {
        $dir = $this->config['output_dir'];
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        
        file_put_contents("{$dir}/benchmark_{$timestamp}.md", $this->generateReport());
        file_put_contents("{$dir}/benchmark_{$timestamp}.json", $this->exportJson());
        
        echo "📁 Reports saved to: {$dir}/\n";
    }
}
