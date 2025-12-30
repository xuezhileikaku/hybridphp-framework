<?php

declare(strict_types=1);

namespace HybridPHP\Core\Middleware;

use HybridPHP\Core\Monitoring\PerformanceMonitor;
use HybridPHP\Core\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Amp\Future;
use function Amp\async;

/**
 * Performance monitoring middleware
 */
class PerformanceMonitoringMiddleware implements MiddlewareInterface
{
    private PerformanceMonitor $performanceMonitor;
    private array $config;

    public function __construct(PerformanceMonitor $performanceMonitor, array $config = [])
    {
        $this->performanceMonitor = $performanceMonitor;
        $this->config = array_merge([
            'track_requests' => true,
            'track_response_size' => true,
            'track_memory_usage' => true,
            'exclude_paths' => ['/monitoring', '/health'],
        ], $config);
    }

    /**
     * Process the request with performance monitoring
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): Future
    {
        return async(function () use ($request, $handler) {
            $path = $request->getUri()->getPath();

            // Skip monitoring for excluded paths
            if ($this->shouldExcludePath($path)) {
                return $handler->handle($request)->await();
            }

            $requestId = $this->generateRequestId();
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);

            // Record request start
            if ($this->config['track_requests']) {
                $this->performanceMonitor->recordRequestStart(
                    $requestId,
                    $request->getMethod(),
                    $path
                );
            }

            try {
                $response = $handler->handle($request)->await();

                // Calculate metrics
                $duration = microtime(true) - $startTime;
                $memoryUsed = memory_get_usage(true) - $startMemory;
                $responseSize = $this->config['track_response_size'] ?
                    strlen($response->getBody()) : null;

                // Record successful request
                if ($this->config['track_requests']) {
                    $this->performanceMonitor->recordRequestEnd(
                        $requestId,
                        $response->getStatus(),
                        $responseSize
                    );
                }

                // Add performance headers (optional)
                $response = $this->addPerformanceHeaders($response, $duration, $memoryUsed);

                return $response;

            } catch (\Throwable $e) {
                // Record failed request
                if ($this->config['track_requests']) {
                    $this->performanceMonitor->recordRequestEnd($requestId, 500);
                }

                throw $e;
            }
        });
    }

    /**
     * Check if path should be excluded from monitoring
     */
    private function shouldExcludePath(string $path): bool
    {
        foreach ($this->config['exclude_paths'] as $excludePath) {
            if (strpos($path, $excludePath) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Generate unique request ID
     */
    private function generateRequestId(): string
    {
        return uniqid('req_', true);
    }

    /**
     * Add performance headers to response
     */
    private function addPerformanceHeaders(
        ResponseInterface $response, 
        float $duration, 
        int $memoryUsed
    ): ResponseInterface {
        return $response
            ->withHeader('X-Response-Time', number_format($duration * 1000, 2) . 'ms')
            ->withHeader('X-Memory-Usage', number_format($memoryUsed / 1024 / 1024, 2) . 'MB')
            ->withHeader('X-Peak-Memory', number_format(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'MB');
    }
}