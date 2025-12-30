<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\Interceptors;

use HybridPHP\Core\Grpc\Context;
use HybridPHP\Core\Grpc\InterceptorInterface;
use HybridPHP\Core\Grpc\Status;

/**
 * Metrics collection interceptor for gRPC calls
 */
class MetricsInterceptor implements InterceptorInterface
{
    protected array $metrics = [
        'requests_total' => 0,
        'requests_success' => 0,
        'requests_failed' => 0,
        'latency_sum' => 0,
        'latency_count' => 0,
        'by_method' => [],
        'by_status' => [],
    ];

    protected array $histogramBuckets;

    public function __construct(array $histogramBuckets = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10])
    {
        $this->histogramBuckets = $histogramBuckets;
    }

    public function intercept(mixed $request, Context $context, callable $next): mixed
    {
        $startTime = microtime(true);
        $method = $context->getValue('method') ?? 'unknown';

        $this->metrics['requests_total']++;
        $this->initMethodMetrics($method);
        $this->metrics['by_method'][$method]['requests']++;

        try {
            $response = $next($request, $context);
            
            $duration = microtime(true) - $startTime;
            
            $this->recordSuccess($method, $duration);

            return $response;

        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;
            $status = $e instanceof \HybridPHP\Core\Grpc\GrpcException 
                ? $e->getStatus() 
                : Status::INTERNAL;
            
            $this->recordFailure($method, $duration, $status);

            throw $e;
        }
    }

    protected function initMethodMetrics(string $method): void
    {
        if (!isset($this->metrics['by_method'][$method])) {
            $this->metrics['by_method'][$method] = [
                'requests' => 0,
                'success' => 0,
                'failed' => 0,
                'latency_sum' => 0,
                'latency_count' => 0,
                'histogram' => array_fill_keys($this->histogramBuckets, 0),
            ];
        }
    }

    protected function recordSuccess(string $method, float $duration): void
    {
        $this->metrics['requests_success']++;
        $this->metrics['latency_sum'] += $duration;
        $this->metrics['latency_count']++;

        $this->metrics['by_method'][$method]['success']++;
        $this->metrics['by_method'][$method]['latency_sum'] += $duration;
        $this->metrics['by_method'][$method]['latency_count']++;

        $this->recordHistogram($method, $duration);
        $this->recordStatus(Status::OK);
    }

    protected function recordFailure(string $method, float $duration, Status $status): void
    {
        $this->metrics['requests_failed']++;
        $this->metrics['latency_sum'] += $duration;
        $this->metrics['latency_count']++;

        $this->metrics['by_method'][$method]['failed']++;
        $this->metrics['by_method'][$method]['latency_sum'] += $duration;
        $this->metrics['by_method'][$method]['latency_count']++;

        $this->recordHistogram($method, $duration);
        $this->recordStatus($status);
    }

    protected function recordHistogram(string $method, float $duration): void
    {
        foreach ($this->histogramBuckets as $bucket) {
            if ($duration <= $bucket) {
                $this->metrics['by_method'][$method]['histogram'][$bucket]++;
            }
        }
    }

    protected function recordStatus(Status $status): void
    {
        $statusName = $status->name;
        if (!isset($this->metrics['by_status'][$statusName])) {
            $this->metrics['by_status'][$statusName] = 0;
        }
        $this->metrics['by_status'][$statusName]++;
    }

    /**
     * Get all metrics
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Get metrics in Prometheus format
     */
    public function getPrometheusMetrics(): string
    {
        $output = [];

        // Total requests
        $output[] = '# HELP grpc_requests_total Total number of gRPC requests';
        $output[] = '# TYPE grpc_requests_total counter';
        $output[] = "grpc_requests_total {$this->metrics['requests_total']}";

        // Success/failure
        $output[] = '# HELP grpc_requests_success_total Total number of successful gRPC requests';
        $output[] = '# TYPE grpc_requests_success_total counter';
        $output[] = "grpc_requests_success_total {$this->metrics['requests_success']}";

        $output[] = '# HELP grpc_requests_failed_total Total number of failed gRPC requests';
        $output[] = '# TYPE grpc_requests_failed_total counter';
        $output[] = "grpc_requests_failed_total {$this->metrics['requests_failed']}";

        // Latency
        if ($this->metrics['latency_count'] > 0) {
            $avgLatency = $this->metrics['latency_sum'] / $this->metrics['latency_count'];
            $output[] = '# HELP grpc_request_duration_seconds Request duration in seconds';
            $output[] = '# TYPE grpc_request_duration_seconds summary';
            $output[] = "grpc_request_duration_seconds_sum {$this->metrics['latency_sum']}";
            $output[] = "grpc_request_duration_seconds_count {$this->metrics['latency_count']}";
        }

        // By method
        foreach ($this->metrics['by_method'] as $method => $methodMetrics) {
            $output[] = "grpc_requests_total{method=\"{$method}\"} {$methodMetrics['requests']}";
        }

        // By status
        foreach ($this->metrics['by_status'] as $status => $count) {
            $output[] = "grpc_requests_total{status=\"{$status}\"} {$count}";
        }

        return implode("\n", $output);
    }

    /**
     * Reset metrics
     */
    public function reset(): void
    {
        $this->metrics = [
            'requests_total' => 0,
            'requests_success' => 0,
            'requests_failed' => 0,
            'latency_sum' => 0,
            'latency_count' => 0,
            'by_method' => [],
            'by_status' => [],
        ];
    }
}
