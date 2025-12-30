<?php
namespace HybridPHP\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use HybridPHP\Core\Http\Response;
use Amp\Redis\Redis;

/**
 * Async rate limiting middleware
 * Implements token bucket and sliding window algorithms
 */
class RateLimitMiddleware extends AbstractMiddleware
{
    private ?Redis $redis;
    private array $config;
    private array $memoryStore = [];

    public function __construct(?Redis $redis = null, array $config = [])
    {
        $this->redis = $redis;
        $this->config = array_merge([
            'algorithm' => 'token_bucket', // token_bucket, sliding_window, fixed_window
            'max_requests' => 100,
            'window_seconds' => 3600, // 1 hour
            'burst_size' => 10, // For token bucket
            'refill_rate' => 1, // Tokens per second for token bucket
            'key_generator' => null, // Custom key generator function
            'skip_successful_requests' => false,
            'headers' => [
                'limit' => 'X-RateLimit-Limit',
                'remaining' => 'X-RateLimit-Remaining',
                'reset' => 'X-RateLimit-Reset',
                'retry_after' => 'Retry-After',
            ],
        ], $config);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key = $this->generateKey($request);
        
        // Check rate limit
        $result = $this->checkRateLimit($key);
        
        if (!$result['allowed']) {
            return $this->rateLimitExceededResponse($result);
        }

        // Process the request
        $response = $handler->handle($request);
        
        // Update rate limit after successful request if configured
        if (!$this->config['skip_successful_requests'] || $response->getStatusCode() >= 400) {
            $this->updateRateLimit($key);
        }

        // Add rate limit headers
        return $this->addRateLimitHeaders($response, $result);
    }

    /**
     * Check if request is within rate limit
     *
     * @param string $key
     * @return array
     */
    private function checkRateLimit(string $key): array
    {
        switch ($this->config['algorithm']) {
            case 'token_bucket':
                return $this->checkTokenBucket($key);
            case 'sliding_window':
                return $this->checkSlidingWindow($key);
            case 'fixed_window':
                return $this->checkFixedWindow($key);
            default:
                throw new \InvalidArgumentException('Invalid rate limiting algorithm');
        }
    }

    /**
     * Token bucket algorithm implementation
     *
     * @param string $key
     * @return array
     */
    private function checkTokenBucket(string $key): array
    {
        $now = time();
        $bucketKey = "rate_limit:bucket:{$key}";
        
        if ($this->redis) {
            // For now, we'll use memory store since Redis operations need to be async
            // In a real implementation, you'd use async Redis operations
            $bucket = $this->memoryStore[$bucketKey] ?? [
                'tokens' => $this->config['burst_size'],
                'last_refill' => $now
            ];
            $tokens = $bucket['tokens'];
            $lastRefill = $bucket['last_refill'];
        } else {
            $bucket = $this->memoryStore[$bucketKey] ?? [
                'tokens' => $this->config['burst_size'],
                'last_refill' => $now
            ];
            $tokens = $bucket['tokens'];
            $lastRefill = $bucket['last_refill'];
        }

        // Calculate tokens to add based on time elapsed
        $elapsed = $now - $lastRefill;
        $tokensToAdd = $elapsed * $this->config['refill_rate'];
        $tokens = min($this->config['burst_size'], $tokens + $tokensToAdd);

        $allowed = $tokens >= 1;
        $remaining = max(0, floor($tokens) - ($allowed ? 1 : 0));

        return [
            'allowed' => $allowed,
            'tokens' => $tokens,
            'remaining' => $remaining,
            'reset_time' => $now + ($this->config['burst_size'] - $tokens) / $this->config['refill_rate'],
            'last_refill' => $now,
            'key' => $bucketKey,
        ];
    }

    /**
     * Sliding window algorithm implementation
     *
     * @param string $key
     * @return array
     */
    private function checkSlidingWindow(string $key): array
    {
        $now = time();
        $windowKey = "rate_limit:window:{$key}";
        $windowStart = $now - $this->config['window_seconds'];

        if ($this->redis) {
            // For now, we'll use memory store since Redis operations need to be async
            // In a real implementation, you'd use async Redis operations
            $requests = $this->memoryStore[$windowKey] ?? [];
            
            // Remove old requests
            $requests = array_filter($requests, fn($timestamp) => $timestamp > $windowStart);
            $this->memoryStore[$windowKey] = $requests;
            
            $count = count($requests);
        } else {
            $requests = $this->memoryStore[$windowKey] ?? [];
            
            // Remove old requests
            $requests = array_filter($requests, fn($timestamp) => $timestamp > $windowStart);
            $this->memoryStore[$windowKey] = $requests;
            
            $count = count($requests);
        }

        $allowed = $count < $this->config['max_requests'];
        $remaining = max(0, $this->config['max_requests'] - $count - ($allowed ? 1 : 0));

        return [
            'allowed' => $allowed,
            'count' => $count,
            'remaining' => $remaining,
            'reset_time' => $now + $this->config['window_seconds'],
            'key' => $windowKey,
        ];
    }

    /**
     * Fixed window algorithm implementation
     *
     * @param string $key
     * @return array
     */
    private function checkFixedWindow(string $key): array
    {
        $now = time();
        $windowStart = floor($now / $this->config['window_seconds']) * $this->config['window_seconds'];
        $windowKey = "rate_limit:fixed:{$key}:{$windowStart}";

        if ($this->redis) {
            // For now, we'll use memory store since Redis operations need to be async
            // In a real implementation, you'd use async Redis operations
            $count = $this->memoryStore[$windowKey] ?? 0;
        } else {
            $count = $this->memoryStore[$windowKey] ?? 0;
        }

        $allowed = $count < $this->config['max_requests'];
        $remaining = max(0, $this->config['max_requests'] - $count - ($allowed ? 1 : 0));

        return [
            'allowed' => $allowed,
            'count' => (int) $count,
            'remaining' => $remaining,
            'reset_time' => $windowStart + $this->config['window_seconds'],
            'key' => $windowKey,
        ];
    }

    /**
     * Update rate limit after processing request
     *
     * @param string $key
     * @return void
     */
    private function updateRateLimit(string $key): void
    {
        switch ($this->config['algorithm']) {
            case 'token_bucket':
                $this->updateTokenBucket($key);
                break;
            case 'sliding_window':
                $this->updateSlidingWindow($key);
                break;
            case 'fixed_window':
                $this->updateFixedWindow($key);
                break;
        }
    }

    /**
     * Update token bucket after request
     *
     * @param string $key
     * @return void
     */
    private function updateTokenBucket(string $key): void
    {
        $result = $this->checkTokenBucket($key);
        $bucketKey = $result['key'];
        
        if ($result['allowed']) {
            $newTokens = $result['tokens'] - 1;
            
            if ($this->redis) {
                // For now, we'll use memory store since Redis operations need to be async
                // In a real implementation, you'd use async Redis operations
                $this->memoryStore[$bucketKey] = [
                    'tokens' => $newTokens,
                    'last_refill' => $result['last_refill']
                ];
            } else {
                $this->memoryStore[$bucketKey] = [
                    'tokens' => $newTokens,
                    'last_refill' => $result['last_refill']
                ];
            }
        }
    }

    /**
     * Update sliding window after request
     *
     * @param string $key
     * @return void
     */
    private function updateSlidingWindow(string $key): void
    {
        $now = time();
        $windowKey = "rate_limit:window:{$key}";
        
        if ($this->redis) {
            // For now, we'll use memory store since Redis operations need to be async
            // In a real implementation, you'd use async Redis operations
            $this->memoryStore[$windowKey][] = $now;
        } else {
            $this->memoryStore[$windowKey][] = $now;
        }
    }

    /**
     * Update fixed window after request
     *
     * @param string $key
     * @return void
     */
    private function updateFixedWindow(string $key): void
    {
        $now = time();
        $windowStart = floor($now / $this->config['window_seconds']) * $this->config['window_seconds'];
        $windowKey = "rate_limit:fixed:{$key}:{$windowStart}";
        
        if ($this->redis) {
            // For now, we'll use memory store since Redis operations need to be async
            // In a real implementation, you'd use async Redis operations
            $this->memoryStore[$windowKey] = ($this->memoryStore[$windowKey] ?? 0) + 1;
        } else {
            $this->memoryStore[$windowKey] = ($this->memoryStore[$windowKey] ?? 0) + 1;
        }
    }

    /**
     * Generate rate limit key for the request
     *
     * @param ServerRequestInterface $request
     * @return string
     */
    private function generateKey(ServerRequestInterface $request): string
    {
        if ($this->config['key_generator'] && is_callable($this->config['key_generator'])) {
            return call_user_func($this->config['key_generator'], $request);
        }

        // Default key generation based on IP and user
        $ip = $this->getClientIp($request);
        $user = $request->getAttribute('user');
        $userId = $user['id'] ?? 'anonymous';
        
        return "ip:{$ip}:user:{$userId}";
    }

    /**
     * Get client IP address
     *
     * @param ServerRequestInterface $request
     * @return string
     */
    private function getClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Add rate limit headers to response
     *
     * @param ResponseInterface $response
     * @param array $result
     * @return ResponseInterface
     */
    private function addRateLimitHeaders(ResponseInterface $response, array $result): ResponseInterface
    {
        $headers = $this->config['headers'];
        
        $response = $response->withHeader($headers['limit'], (string) $this->config['max_requests']);
        $response = $response->withHeader($headers['remaining'], (string) $result['remaining']);
        $response = $response->withHeader($headers['reset'], (string) $result['reset_time']);
        
        return $response;
    }

    /**
     * Return rate limit exceeded response
     *
     * @param array $result
     * @return ResponseInterface
     */
    private function rateLimitExceededResponse(array $result): ResponseInterface
    {
        $retryAfter = max(1, $result['reset_time'] - time());
        
        return new Response(429, [
            'Content-Type' => 'application/json',
            $this->config['headers']['limit'] => (string) $this->config['max_requests'],
            $this->config['headers']['remaining'] => '0',
            $this->config['headers']['reset'] => (string) $result['reset_time'],
            $this->config['headers']['retry_after'] => (string) $retryAfter,
        ], json_encode([
            'error' => 'Rate limit exceeded',
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => $retryAfter,
        ]));
    }
}