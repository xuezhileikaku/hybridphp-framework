<?php

declare(strict_types=1);

namespace HybridPHP\Core\Monitoring;

use HybridPHP\Core\Application;
use Amp\Future;
use Psr\Log\LoggerInterface;
use function Amp\async;
use function Amp\delay;

/**
 * Performance monitoring service
 */
class PerformanceMonitor
{
    private MetricsCollector $metricsCollector;
    private AlertManager $alertManager;
    private Application $application;
    private ?LoggerInterface $logger;
    private array $config;
    private bool $monitoring = false;
    private array $requestMetrics = [];
    private array $coroutineMetrics = [];

    public function __construct(
        MetricsCollector $metricsCollector,
        AlertManager $alertManager,
        Application $application,
        ?LoggerInterface $logger = null,
        array $config = []
    ) {
        $this->metricsCollector = $metricsCollector;
        $this->alertManager = $alertManager;
        $this->application = $application;
        $this->logger = $logger;
        $this->config = array_merge([
            'monitoring_interval' => 5,
            'request_timeout_threshold' => 30.0,
            'memory_threshold' => 0.9,
            'cpu_threshold' => 0.8,
            'coroutine_threshold' => 1000,
            'response_time_percentiles' => [50, 90, 95, 99],
        ], $config);
    }

    /**
     * Start performance monitoring
     */
    public function start(): Future
    {
        return async(function () {
            if ($this->monitoring) {
                return;
            }

            $this->monitoring = true;

            if ($this->logger) {
                $this->logger->info('Performance monitor started');
            }

            // Start metrics collection
            $this->metricsCollector->start()->await();

            // Start performance monitoring loop
            while ($this->monitoring) {
                try {
                    $this->collectPerformanceMetrics();
                    $this->analyzePerformance();
                    $this->checkThresholds();
                } catch (\Throwable $e) {
                    if ($this->logger) {
                        $this->logger->error('Performance monitoring failed', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }

                delay($this->config['monitoring_interval']);
            }
        });
    }

    /**
     * Stop performance monitoring
     */
    public function stop(): void
    {
        $this->monitoring = false;
        $this->metricsCollector->stop();

        if ($this->logger) {
            $this->logger->info('Performance monitor stopped');
        }
    }

    /**
     * Record request start
     */
    public function recordRequestStart(string $requestId, string $method, string $path): void
    {
        $this->requestMetrics[$requestId] = [
            'method' => $method,
            'path' => $path,
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];

        $this->metricsCollector->incrementCounter('http_requests_total', [
            'method' => $method,
            'path' => $this->normalizePath($path),
        ]);
    }

    /**
     * Record request end
     */
    public function recordRequestEnd(string $requestId, int $statusCode, ?int $responseSize = null): void
    {
        if (!isset($this->requestMetrics[$requestId])) {
            return;
        }

        $request = $this->requestMetrics[$requestId];
        $endTime = microtime(true);
        $duration = $endTime - $request['start_time'];
        $memoryUsed = memory_get_usage(true) - $request['memory_start'];

        $labels = [
            'method' => $request['method'],
            'path' => $this->normalizePath($request['path']),
            'status' => (string) $statusCode,
        ];

        $this->metricsCollector->observeHistogram('http_request_duration_seconds', $duration, $labels);
        $this->metricsCollector->observeHistogram('http_request_memory_bytes', $memoryUsed, $labels);

        if ($responseSize !== null) {
            $this->metricsCollector->observeHistogram('http_response_size_bytes', $responseSize, $labels);
        }

        if ($duration > $this->config['request_timeout_threshold']) {
            $this->alertManager->trigger('slow_request', [
                'request_id' => $requestId,
                'method' => $request['method'],
                'path' => $request['path'],
                'duration' => $duration,
                'threshold' => $this->config['request_timeout_threshold'],
            ], 'warning');
        }

        unset($this->requestMetrics[$requestId]);
    }

    /**
     * Record coroutine start
     */
    public function recordCoroutineStart(string $coroutineId, string $name): void
    {
        $this->coroutineMetrics[$coroutineId] = [
            'name' => $name,
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];

        $this->metricsCollector->incrementCounter('coroutines_started_total', ['name' => $name]);
    }

    /**
     * Record coroutine end
     */
    public function recordCoroutineEnd(string $coroutineId, bool $success = true): void
    {
        if (!isset($this->coroutineMetrics[$coroutineId])) {
            return;
        }

        $coroutine = $this->coroutineMetrics[$coroutineId];
        $endTime = microtime(true);
        $duration = $endTime - $coroutine['start_time'];
        $memoryUsed = memory_get_usage(true) - $coroutine['memory_start'];

        $labels = [
            'name' => $coroutine['name'],
            'status' => $success ? 'success' : 'error',
        ];

        $this->metricsCollector->observeHistogram('coroutine_duration_seconds', $duration, $labels);
        $this->metricsCollector->observeHistogram('coroutine_memory_bytes', $memoryUsed, $labels);
        $this->metricsCollector->incrementCounter('coroutines_finished_total', $labels);

        unset($this->coroutineMetrics[$coroutineId]);
    }

    /**
     * Get performance report
     */
    public function getPerformanceReport(): array
    {
        $metrics = $this->metricsCollector->getMetrics();

        return [
            'timestamp' => date('c'),
            'system' => $this->getSystemPerformance(),
            'application' => $this->getApplicationPerformance(),
            'requests' => $this->getRequestPerformance($metrics),
            'coroutines' => $this->getCoroutinePerformance($metrics),
            'alerts' => $this->alertManager->getActiveAlerts(),
        ];
    }

    /**
     * Get metrics in Prometheus format
     */
    public function getPrometheusMetrics(): string
    {
        return $this->metricsCollector->getPrometheusMetrics();
    }

    /**
     * Get metrics in JSON format for ELK
     */
    public function getJsonMetrics(): array
    {
        return $this->metricsCollector->getJsonMetrics();
    }

    private function collectPerformanceMetrics(): void
    {
        $runningCoroutines = $this->application->getRunningCoroutines();
        $this->metricsCollector->setGauge('app_coroutines_active', count($runningCoroutines));
        $this->metricsCollector->setGauge('app_requests_active', count($this->requestMetrics));

        $uptime = time() - ($_SERVER['REQUEST_TIME'] ?? time());
        $this->metricsCollector->setGauge('app_uptime_seconds', $uptime);

        $this->metricsCollector->setGauge('app_running', $this->application->isRunning() ? 1 : 0);
        $this->metricsCollector->setGauge('app_shutting_down', $this->application->isShuttingDown() ? 1 : 0);
    }

    private function analyzePerformance(): void
    {
        $metrics = $this->metricsCollector->getMetrics();
        $this->analyzeRequestPatterns($metrics);
        $this->analyzeResourceUsage($metrics);
        $this->analyzeCoroutinePerformance($metrics);
    }

    private function checkThresholds(): void
    {
        $metrics = $this->metricsCollector->getMetrics();

        foreach ($metrics['gauges'] as $gauge) {
            if ($gauge['name'] === 'php_memory_usage_ratio' && $gauge['value'] > $this->config['memory_threshold']) {
                $this->alertManager->trigger('high_memory_usage', [
                    'usage_ratio' => $gauge['value'],
                    'threshold' => $this->config['memory_threshold'],
                ], 'warning');
            }
        }

        foreach ($metrics['gauges'] as $gauge) {
            if ($gauge['name'] === 'app_coroutines_active' && $gauge['value'] > $this->config['coroutine_threshold']) {
                $this->alertManager->trigger('high_coroutine_count', [
                    'count' => $gauge['value'],
                    'threshold' => $this->config['coroutine_threshold'],
                ], 'warning');
            }
        }

        foreach ($metrics['gauges'] as $gauge) {
            if ($gauge['name'] === 'system_load_1m' && $gauge['value'] > $this->config['cpu_threshold']) {
                $this->alertManager->trigger('high_cpu_load', [
                    'load' => $gauge['value'],
                    'threshold' => $this->config['cpu_threshold'],
                ], 'warning');
            }
        }
    }

    private function getSystemPerformance(): array
    {
        $metrics = $this->metricsCollector->getMetrics();
        $system = [];

        foreach ($metrics['gauges'] as $gauge) {
            if (strpos($gauge['name'], 'php_memory_') === 0 ||
                strpos($gauge['name'], 'system_load_') === 0 ||
                strpos($gauge['name'], 'disk_') === 0 ||
                strpos($gauge['name'], 'process_cpu_') === 0) {
                $system[$gauge['name']] = $gauge['value'];
            }
        }

        return $system;
    }

    private function getApplicationPerformance(): array
    {
        $metrics = $this->metricsCollector->getMetrics();
        $application = [];

        foreach ($metrics['gauges'] as $gauge) {
            if (strpos($gauge['name'], 'app_') === 0) {
                $application[$gauge['name']] = $gauge['value'];
            }
        }

        return $application;
    }

    private function getRequestPerformance(array $metrics): array
    {
        $requests = [
            'total' => 0,
            'by_method' => [],
            'by_status' => [],
            'response_times' => [],
        ];

        foreach ($metrics['counters'] as $counter) {
            if ($counter['name'] === 'http_requests_total') {
                $requests['total'] += $counter['value'];

                if (isset($counter['labels']['method'])) {
                    $method = $counter['labels']['method'];
                    $requests['by_method'][$method] = ($requests['by_method'][$method] ?? 0) + $counter['value'];
                }

                if (isset($counter['labels']['status'])) {
                    $status = $counter['labels']['status'];
                    $requests['by_status'][$status] = ($requests['by_status'][$status] ?? 0) + $counter['value'];
                }
            }
        }

        foreach ($metrics['histograms'] as $histogram) {
            if ($histogram['name'] === 'http_request_duration_seconds') {
                $requests['response_times'][] = [
                    'labels' => $histogram['labels'],
                    'count' => $histogram['count'],
                    'sum' => $histogram['sum'],
                    'avg' => $histogram['count'] > 0 ? $histogram['sum'] / $histogram['count'] : 0,
                ];
            }
        }

        return $requests;
    }

    private function getCoroutinePerformance(array $metrics): array
    {
        $coroutines = [
            'started' => 0,
            'finished' => 0,
            'active' => count($this->coroutineMetrics),
            'by_name' => [],
            'durations' => [],
        ];

        foreach ($metrics['counters'] as $counter) {
            if ($counter['name'] === 'coroutines_started_total') {
                $coroutines['started'] += $counter['value'];

                if (isset($counter['labels']['name'])) {
                    $name = $counter['labels']['name'];
                    $coroutines['by_name'][$name] = ($coroutines['by_name'][$name] ?? 0) + $counter['value'];
                }
            } elseif ($counter['name'] === 'coroutines_finished_total') {
                $coroutines['finished'] += $counter['value'];
            }
        }

        foreach ($metrics['histograms'] as $histogram) {
            if ($histogram['name'] === 'coroutine_duration_seconds') {
                $coroutines['durations'][] = [
                    'labels' => $histogram['labels'],
                    'count' => $histogram['count'],
                    'sum' => $histogram['sum'],
                    'avg' => $histogram['count'] > 0 ? $histogram['sum'] / $histogram['count'] : 0,
                ];
            }
        }

        return $coroutines;
    }

    private function analyzeRequestPatterns(array $metrics): void
    {
        if ($this->logger) {
            $requestCount = 0;
            foreach ($metrics['counters'] as $counter) {
                if ($counter['name'] === 'http_requests_total') {
                    $requestCount += $counter['value'];
                }
            }

            if ($requestCount > 0) {
                $this->logger->debug('Request pattern analysis', [
                    'total_requests' => $requestCount,
                    'active_requests' => count($this->requestMetrics),
                ]);
            }
        }
    }

    private function analyzeResourceUsage(array $metrics): void
    {
        foreach ($metrics['gauges'] as $gauge) {
            if ($gauge['name'] === 'php_memory_usage_ratio') {
                if ($gauge['value'] > 0.8) {
                    if ($this->logger) {
                        $this->logger->warning('High memory usage detected', [
                            'usage_ratio' => $gauge['value'],
                        ]);
                    }
                }
            }
        }
    }

    private function analyzeCoroutinePerformance(array $metrics): void
    {
        $activeCount = count($this->coroutineMetrics);

        if ($activeCount > $this->config['coroutine_threshold'] * 0.8) {
            if ($this->logger) {
                $this->logger->warning('High coroutine count detected', [
                    'active_count' => $activeCount,
                    'threshold' => $this->config['coroutine_threshold'],
                ]);
            }
        }
    }

    private function normalizePath(string $path): string
    {
        $path = preg_replace('/\/\d+/', '/{id}', $path);
        $path = preg_replace('/\/[a-f0-9-]{36}/', '/{uuid}', $path);

        return $path;
    }
}
