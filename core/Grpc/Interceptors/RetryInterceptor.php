<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\Interceptors;

use HybridPHP\Core\Grpc\Context;
use HybridPHP\Core\Grpc\InterceptorInterface;
use HybridPHP\Core\Grpc\GrpcException;
use HybridPHP\Core\Grpc\Status;

/**
 * Retry interceptor for gRPC calls with exponential backoff
 */
class RetryInterceptor implements InterceptorInterface
{
    protected int $maxRetries;
    protected float $initialDelay;
    protected float $maxDelay;
    protected float $multiplier;
    protected array $retryableStatuses;

    public function __construct(
        int $maxRetries = 3,
        float $initialDelay = 0.1,
        float $maxDelay = 10.0,
        float $multiplier = 2.0,
        array $retryableStatuses = []
    ) {
        $this->maxRetries = $maxRetries;
        $this->initialDelay = $initialDelay;
        $this->maxDelay = $maxDelay;
        $this->multiplier = $multiplier;
        $this->retryableStatuses = $retryableStatuses ?: [
            Status::UNAVAILABLE,
            Status::RESOURCE_EXHAUSTED,
            Status::ABORTED,
            Status::INTERNAL,
            Status::UNKNOWN,
        ];
    }

    public function intercept(mixed $request, Context $context, callable $next): mixed
    {
        $attempt = 0;
        $delay = $this->initialDelay;
        $lastException = null;

        while ($attempt <= $this->maxRetries) {
            try {
                return $next($request, $context);
            } catch (GrpcException $e) {
                $lastException = $e;

                // Check if we should retry
                if (!$this->shouldRetry($e, $attempt)) {
                    throw $e;
                }

                // Check deadline
                if ($context->isDeadlineExceeded()) {
                    throw GrpcException::deadlineExceeded('Deadline exceeded during retry');
                }

                $attempt++;

                if ($attempt <= $this->maxRetries) {
                    // Add jitter to delay
                    $jitteredDelay = $delay * (0.5 + mt_rand() / mt_getrandmax());
                    \Amp\delay($jitteredDelay);
                    
                    // Exponential backoff
                    $delay = min($delay * $this->multiplier, $this->maxDelay);
                }
            }
        }

        throw $lastException ?? GrpcException::internal('Retry failed');
    }

    protected function shouldRetry(GrpcException $e, int $attempt): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        return in_array($e->getStatus(), $this->retryableStatuses);
    }
}
