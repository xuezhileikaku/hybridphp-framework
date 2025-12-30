<?php

namespace HybridPHP\Core\Cache;

use Amp\Future;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use function Amp\async;

/**
 * HTTP Cache Middleware
 */
class CacheMiddleware implements MiddlewareInterface
{
    private CacheManager $cacheManager;
    private array $config;

    public function __construct(CacheManager $cacheManager, array $config = [])
    {
        $this->cacheManager = $cacheManager;
        $this->config = array_merge([
            'ttl' => 3600,
            'vary' => ['Accept', 'Accept-Encoding'],
            'cache_methods' => ['GET', 'HEAD'],
            'exclude_paths' => ['/api/auth', '/admin'],
            'cache_store' => 'multilevel',
        ], $config);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): Future
    {
        return async(function () use ($request, $handler) {
            // Check if request should be cached
            if (!$this->shouldCache($request)) {
                return $handler->handle($request)->await();
            }

            $cacheKey = $this->generateCacheKey($request);
            $cache = $this->cacheManager->store($this->config['cache_store']);

            // Try to get cached response
            $cachedResponse = $cache->get($cacheKey)->await();
            if ($cachedResponse !== null) {
                return $this->createResponseFromCache($cachedResponse);
            }

            // Process request and cache response
            $response = $handler->handle($request)->await();
            
            if ($this->shouldCacheResponse($response)) {
                $cacheData = $this->serializeResponse($response);
                $cache->set($cacheKey, $cacheData, $this->config['ttl'])->await();
            }

            return $response;
        });
    }

    /**
     * Check if request should be cached
     */
    private function shouldCache(ServerRequestInterface $request): bool
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        // Check method
        if (!in_array($method, $this->config['cache_methods'])) {
            return false;
        }

        // Check excluded paths
        foreach ($this->config['exclude_paths'] as $excludePath) {
            if (str_starts_with($path, $excludePath)) {
                return false;
            }
        }

        // Check cache control headers
        $cacheControl = $request->getHeaderLine('Cache-Control');
        if (str_contains($cacheControl, 'no-cache') || str_contains($cacheControl, 'no-store')) {
            return false;
        }

        return true;
    }

    /**
     * Check if response should be cached
     */
    private function shouldCacheResponse(ResponseInterface $response): bool
    {
        $statusCode = $response->getStatusCode();
        
        // Only cache successful responses
        if ($statusCode < 200 || $statusCode >= 300) {
            return false;
        }

        // Check cache control headers
        $cacheControl = $response->getHeaderLine('Cache-Control');
        if (str_contains($cacheControl, 'no-cache') || 
            str_contains($cacheControl, 'no-store') || 
            str_contains($cacheControl, 'private')) {
            return false;
        }

        return true;
    }

    /**
     * Generate cache key for request
     */
    private function generateCacheKey(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $key = $request->getMethod() . ':' . $uri->getPath();
        
        // Include query parameters
        if ($uri->getQuery()) {
            $key .= '?' . $uri->getQuery();
        }

        // Include vary headers
        foreach ($this->config['vary'] as $header) {
            $value = $request->getHeaderLine($header);
            if ($value) {
                $key .= ':' . $header . '=' . $value;
            }
        }

        return 'http_cache:' . md5($key);
    }

    /**
     * Serialize response for caching
     */
    private function serializeResponse(ResponseInterface $response): array
    {
        return [
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body' => (string) $response->getBody(),
            'reason' => $response->getReasonPhrase(),
            'cached_at' => time(),
        ];
    }

    /**
     * Create response from cached data
     */
    private function createResponseFromCache(array $cacheData): ResponseInterface
    {
        // This would need to be implemented with your specific Response class
        // For now, returning a placeholder
        throw new \RuntimeException('Response creation from cache not implemented');
    }
}