<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\Interceptors;

use HybridPHP\Core\Grpc\Context;
use HybridPHP\Core\Grpc\InterceptorInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Logging interceptor for gRPC calls
 */
class LoggingInterceptor implements InterceptorInterface
{
    protected LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    public function intercept(mixed $request, Context $context, callable $next): mixed
    {
        $startTime = microtime(true);
        $requestId = $context->getRequestId() ?? uniqid('grpc_');

        $this->logger->info('gRPC request started', [
            'request_id' => $requestId,
            'metadata' => $context->getMetadata(),
        ]);

        try {
            $response = $next($request, $context);
            
            $duration = microtime(true) - $startTime;
            
            $this->logger->info('gRPC request completed', [
                'request_id' => $requestId,
                'duration_ms' => round($duration * 1000, 2),
            ]);

            return $response;

        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;
            
            $this->logger->error('gRPC request failed', [
                'request_id' => $requestId,
                'duration_ms' => round($duration * 1000, 2),
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            throw $e;
        }
    }
}
