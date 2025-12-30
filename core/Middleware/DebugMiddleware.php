<?php

declare(strict_types=1);

namespace HybridPHP\Core\Middleware;

use HybridPHP\Core\Debug\PerformanceProfiler;
use HybridPHP\Core\Debug\CoroutineDebugger;
use HybridPHP\Core\Debug\QueryAnalyzer;
use HybridPHP\Core\MiddlewareInterface;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Psr\Log\LoggerInterface;

/**
 * Debug middleware for collecting performance and debugging data
 */
class DebugMiddleware implements MiddlewareInterface
{
    private ?PerformanceProfiler $profiler;
    private ?CoroutineDebugger $coroutineDebugger;
    private ?QueryAnalyzer $queryAnalyzer;
    private ?LoggerInterface $logger;
    private array $config;

    public function __construct(
        ?PerformanceProfiler $profiler = null,
        ?CoroutineDebugger $coroutineDebugger = null,
        ?QueryAnalyzer $queryAnalyzer = null,
        ?LoggerInterface $logger = null,
        array $config = []
    ) {
        $this->profiler = $profiler;
        $this->coroutineDebugger = $coroutineDebugger;
        $this->queryAnalyzer = $queryAnalyzer;
        $this->logger = $logger;
        $this->config = array_merge([
            'enabled' => true,
            'profile_requests' => true,
            'add_debug_headers' => true,
            'log_slow_requests' => true,
            'slow_request_threshold' => 1.0, // seconds
            'collect_request_data' => true,
        ], $config);
    }

    /**
     * Process request with debugging
     */
    public function process(Request $request, callable $next): Response
    {
        if (!$this->config['enabled']) {
            return $next($request);
        }

        $requestId = uniqid('req_', true);
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        // Start request profiling
        if ($this->profiler && $this->config['profile_requests']) {
            $this->profiler->startTimer("request_{$requestId}");
            $this->profiler->recordMemorySnapshot("request_start_{$requestId}");
        }

        // Collect request data
        $requestData = $this->config['collect_request_data'] ? $this->collectRequestData($request) : [];

        try {
            // Process request
            $response = $next($request);
            
            // Record successful request
            $this->recordRequestCompletion($requestId, $request, $response, $startTime, $startMemory, $requestData);
            
            return $response;
        } catch (\Throwable $e) {
            // Record failed request
            $this->recordRequestFailure($requestId, $request, $e, $startTime, $startMemory, $requestData);
            
            throw $e;
        }
    }

    /**
     * Record successful request completion
     */
    private function recordRequestCompletion(
        string $requestId,
        Request $request,
        Response $response,
        float $startTime,
        int $startMemory,
        array $requestData
    ): void {
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        $memoryUsed = memory_get_usage(true) - $startMemory;

        // Stop profiling
        if ($this->profiler) {
            $this->profiler->stopTimer("request_{$requestId}");
            $this->profiler->recordMemorySnapshot("request_end_{$requestId}");
        }

        // Log slow requests
        if ($this->config['log_slow_requests'] && 
            $duration > $this->config['slow_request_threshold'] && 
            $this->logger) {
            
            $this->logger->warning('Slow request detected', [
                'request_id' => $requestId,
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'duration' => $duration,
                'memory_used' => $memoryUsed,
                'status_code' => $response->getStatus(),
                'request_data' => $requestData,
            ]);
        }

        // Add debug headers
        if ($this->config['add_debug_headers']) {
            $this->addDebugHeaders($response, $requestId, $duration, $memoryUsed);
        }
    }

    /**
     * Record failed request
     */
    private function recordRequestFailure(
        string $requestId,
        Request $request,
        \Throwable $exception,
        float $startTime,
        int $startMemory,
        array $requestData
    ): void {
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        $memoryUsed = memory_get_usage(true) - $startMemory;

        // Stop profiling
        if ($this->profiler) {
            $this->profiler->stopTimer("request_{$requestId}");
            $this->profiler->recordMemorySnapshot("request_error_{$requestId}");
        }

        // Log failed request
        if ($this->logger) {
            $this->logger->error('Request failed', [
                'request_id' => $requestId,
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'duration' => $duration,
                'memory_used' => $memoryUsed,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'request_data' => $requestData,
            ]);
        }
    }

    /**
     * Collect request data for debugging
     */
    private function collectRequestData(Request $request): array
    {
        return [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'headers' => $this->sanitizeHeaders($request->getHeaders()),
            'query' => $request->getUri()->getQuery(),
            'user_agent' => $request->getHeader('user-agent'),
            'content_type' => $request->getHeader('content-type'),
            'content_length' => $request->getHeader('content-length'),
            'ip' => $this->getClientIp($request),
            'timestamp' => microtime(true),
        ];
    }

    /**
     * Add debug headers to response
     */
    private function addDebugHeaders(Response $response, string $requestId, float $duration, int $memoryUsed): void
    {
        $headers = [
            'X-Debug-Request-ID' => $requestId,
            'X-Debug-Duration' => number_format($duration, 4),
            'X-Debug-Memory' => number_format($memoryUsed / 1024 / 1024, 2) . 'MB',
            'X-Debug-Peak-Memory' => number_format(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'MB',
        ];

        // Add profiler data
        if ($this->profiler) {
            $snapshot = $this->profiler->getSnapshot();
            $headers['X-Debug-Query-Count'] = (string) $snapshot['query_count'];
            $headers['X-Debug-Query-Time'] = number_format($snapshot['total_query_time'], 4);
            $headers['X-Debug-Active-Coroutines'] = (string) $snapshot['active_coroutines'];
        }

        // Add coroutine data
        if ($this->coroutineDebugger) {
            $stats = $this->coroutineDebugger->getStatistics();
            $headers['X-Debug-Coroutine-Total'] = (string) $stats['total_coroutines'];
            $headers['X-Debug-Coroutine-Active'] = (string) $stats['active_coroutines'];
            $headers['X-Debug-Coroutine-Failed'] = (string) $stats['failed_coroutines'];
        }

        // Add query analyzer data
        if ($this->queryAnalyzer) {
            $stats = $this->queryAnalyzer->getStatistics();
            $headers['X-Debug-Slow-Queries'] = (string) $stats['slow_queries'];
            $headers['X-Debug-Duplicate-Queries'] = (string) $stats['duplicate_queries'];
        }

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
    }

    /**
     * Sanitize headers for logging
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitive = ['authorization', 'cookie', 'x-api-key', 'x-auth-token'];
        $sanitized = [];

        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), $sensitive)) {
                $sanitized[$name] = '[REDACTED]';
            } else {
                $sanitized[$name] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Get client IP address
     */
    private function getClientIp(Request $request): string
    {
        $headers = ['x-forwarded-for', 'x-real-ip', 'x-client-ip'];
        
        foreach ($headers as $header) {
            $ip = $request->getHeader($header);
            if ($ip) {
                return explode(',', $ip)[0];
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Enable/disable middleware
     */
    public function setEnabled(bool $enabled): void
    {
        $this->config['enabled'] = $enabled;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}