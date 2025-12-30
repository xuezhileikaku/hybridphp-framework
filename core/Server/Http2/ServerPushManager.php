<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\Http2;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

/**
 * Server Push Manager for HTTP/2
 * 
 * Manages server push resources, generates Link headers,
 * and tracks push promises for HTTP/2 server push functionality.
 * 
 * Supports:
 * - Manual resource registration
 * - Automatic resource detection from HTML
 * - Push rules based on URL patterns
 * - Push promise tracking and statistics
 * - Cache-aware push (respects client cache)
 * 
 * @see RFC 7540 Section 8.2 - Server Push
 */
class ServerPushManager
{
    private Http2Config $config;
    private array $registeredResources = [];
    private array $pushRules = [];
    private array $pushPromises = [];
    private array $pushedPaths = [];
    private int $maxPushResources;
    private bool $autoDetect;
    private array $stats = [
        'total_pushes' => 0,
        'successful_pushes' => 0,
        'cancelled_pushes' => 0,
        'failed_pushes' => 0,
        'bytes_pushed' => 0,
    ];
    
    private array $contentTypeMap = [
        'css' => 'style',
        'js' => 'script',
        'mjs' => 'script',
        'woff' => 'font',
        'woff2' => 'font',
        'ttf' => 'font',
        'otf' => 'font',
        'eot' => 'font',
        'png' => 'image',
        'jpg' => 'image',
        'jpeg' => 'image',
        'gif' => 'image',
        'svg' => 'image',
        'webp' => 'image',
        'avif' => 'image',
        'ico' => 'image',
        'json' => 'fetch',
        'xml' => 'fetch',
        'html' => 'document',
        'htm' => 'document',
    ];
    
    private array $mimeTypeMap = [
        'text/css' => 'style',
        'application/javascript' => 'script',
        'text/javascript' => 'script',
        'application/json' => 'fetch',
        'image/png' => 'image',
        'image/jpeg' => 'image',
        'image/gif' => 'image',
        'image/svg+xml' => 'image',
        'image/webp' => 'image',
        'font/woff' => 'font',
        'font/woff2' => 'font',
        'application/font-woff' => 'font',
        'application/font-woff2' => 'font',
    ];

    public function __construct(Http2Config $config)
    {
        $this->config = $config;
        $this->pushRules = $config->getPushRules();
        $this->maxPushResources = 10; // Default max resources per request
        $this->autoDetect = true;
    }

    /**
     * Register a resource for server push
     */
    public function registerResource(string $path, string $type, array $options = []): void
    {
        $this->registeredResources[$path] = [
            'path' => $path,
            'type' => $type,
            'crossorigin' => $options['crossorigin'] ?? null,
            'nopush' => $options['nopush'] ?? false,
            'priority' => $options['priority'] ?? $this->getDefaultPriority($type),
            'cache_control' => $options['cache_control'] ?? null,
            'content_type' => $options['content_type'] ?? null,
        ];
    }

    /**
     * Register multiple resources at once
     */
    public function registerResources(array $resources): void
    {
        foreach ($resources as $path => $config) {
            if (is_string($config)) {
                $this->registerResource($path, $config);
            } else {
                $this->registerResource(
                    $path,
                    $config['type'] ?? $this->detectType($path),
                    $config
                );
            }
        }
    }

    /**
     * Unregister a resource
     */
    public function unregisterResource(string $path): void
    {
        unset($this->registeredResources[$path]);
    }

    /**
     * Add a push rule for automatic resource detection
     */
    public function addPushRule(string $pattern, array $resources): void
    {
        $this->pushRules[$pattern] = $resources;
    }

    /**
     * Remove a push rule
     */
    public function removePushRule(string $pattern): void
    {
        unset($this->pushRules[$pattern]);
    }

    /**
     * Set maximum push resources per request
     */
    public function setMaxPushResources(int $max): void
    {
        $this->maxPushResources = max(1, $max);
    }

    /**
     * Enable/disable auto-detection of resources from HTML
     */
    public function setAutoDetect(bool $enabled): void
    {
        $this->autoDetect = $enabled;
    }

    /**
     * Get push resources for a request/response pair
     */
    public function getPushResources(Request $request, Response $response): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        
        $resources = [];
        $path = $request->getUri()->getPath();
        
        // Check if client already has resources cached (via cookie or header)
        $cachedResources = $this->getClientCachedResources($request);
        
        // Check registered resources
        foreach ($this->registeredResources as $resource) {
            if (!$resource['nopush'] && !in_array($resource['path'], $cachedResources)) {
                $resources[] = $resource;
            }
        }
        
        // Check push rules
        foreach ($this->pushRules as $pattern => $ruleResources) {
            if ($this->matchesPattern($path, $pattern)) {
                foreach ($ruleResources as $resourcePath => $resourceConfig) {
                    if (in_array($resourcePath, $cachedResources)) {
                        continue;
                    }
                    
                    if (is_string($resourceConfig)) {
                        $resources[] = [
                            'path' => $resourcePath,
                            'type' => $resourceConfig,
                            'priority' => $this->getDefaultPriority($resourceConfig),
                        ];
                    } else {
                        $resources[] = array_merge(
                            ['path' => $resourcePath],
                            $resourceConfig,
                            ['priority' => $resourceConfig['priority'] ?? $this->getDefaultPriority($resourceConfig['type'] ?? 'fetch')]
                        );
                    }
                }
            }
        }
        
        // Auto-detect resources from HTML response
        $contentType = $response->getHeader('content-type') ?? '';
        if ($this->autoDetect && str_contains($contentType, 'text/html')) {
            $body = (string) $response->getBody();
            $autoDetected = $this->detectResourcesFromHtml($body);
            
            // Filter out cached resources
            $autoDetected = array_filter($autoDetected, function($r) use ($cachedResources) {
                return !in_array($r['path'], $cachedResources);
            });
            
            $resources = array_merge($resources, $autoDetected);
        }
        
        // Remove duplicates and limit count
        $resources = $this->deduplicateResources($resources);
        $resources = $this->prioritizeResources($resources);
        
        return array_slice($resources, 0, $this->maxPushResources);
    }

    /**
     * Create push promises for resources
     */
    public function createPushPromises(Request $request, Response $response): array
    {
        $resources = $this->getPushResources($request, $response);
        $authority = $request->getUri()->getHost();
        $port = $request->getUri()->getPort();
        
        if ($port && $port !== 443) {
            $authority .= ':' . $port;
        }
        
        $promises = [];
        foreach ($resources as $resource) {
            $promise = PushPromise::fromResource($resource, $authority);
            $promises[] = $promise;
            $this->pushPromises[] = $promise;
            $this->stats['total_pushes']++;
        }
        
        return $promises;
    }

    /**
     * Mark a path as already pushed (to avoid duplicate pushes)
     */
    public function markPushed(string $path): void
    {
        $this->pushedPaths[$path] = time();
    }

    /**
     * Check if a path was already pushed recently
     */
    public function wasPushed(string $path, int $ttl = 60): bool
    {
        if (!isset($this->pushedPaths[$path])) {
            return false;
        }
        
        return (time() - $this->pushedPaths[$path]) < $ttl;
    }

    /**
     * Get client cached resources from request headers
     */
    protected function getClientCachedResources(Request $request): array
    {
        $cached = [];
        
        // Check for push cache digest (if client supports it)
        $cacheDigest = $request->getHeader('cache-digest');
        if ($cacheDigest) {
            // Parse cache digest header (simplified)
            // Full implementation would decode the digest
        }
        
        // Check for custom header indicating cached resources
        $cachedHeader = $request->getHeader('x-pushed-resources');
        if ($cachedHeader) {
            $cached = array_merge($cached, explode(',', $cachedHeader));
        }
        
        // Check cookie for pushed resources
        $cookies = $request->getCookies();
        if (isset($cookies['_pushed'])) {
            $pushedCookie = json_decode(base64_decode($cookies['_pushed']), true);
            if (is_array($pushedCookie)) {
                $cached = array_merge($cached, $pushedCookie);
            }
        }
        
        return array_map('trim', $cached);
    }

    /**
     * Create Link header for server push
     */
    public function createLinkHeader(array $resource): string
    {
        $path = $resource['path'];
        $type = $resource['type'] ?? $this->detectType($path);
        
        $header = "<{$path}>; rel=preload; as={$type}";
        
        // Add crossorigin attribute for fonts and some scripts
        if (isset($resource['crossorigin'])) {
            $header .= '; crossorigin=' . $resource['crossorigin'];
        } elseif ($type === 'font') {
            $header .= '; crossorigin=anonymous';
        }
        
        // Add nopush directive if specified
        if (!empty($resource['nopush'])) {
            $header .= '; nopush';
        }
        
        // Add type hint for scripts/styles
        if (isset($resource['content_type'])) {
            $header .= '; type=' . $resource['content_type'];
        }
        
        return $header;
    }

    /**
     * Create multiple Link headers
     */
    public function createLinkHeaders(array $resources): array
    {
        return array_map([$this, 'createLinkHeader'], $resources);
    }

    /**
     * Create combined Link header string
     */
    public function createCombinedLinkHeader(array $resources): string
    {
        return implode(', ', $this->createLinkHeaders($resources));
    }

    /**
     * Apply push headers to response
     */
    public function applyPushHeaders(Response $response, Request $request): Response
    {
        if (!$this->isEnabled()) {
            return $response;
        }
        
        $resources = $this->getPushResources($request, $response);
        
        if (empty($resources)) {
            return $response;
        }
        
        // Add Link headers
        $linkHeader = $this->createCombinedLinkHeader($resources);
        $response = $response->withHeader('Link', $linkHeader);
        
        // Track pushed resources
        foreach ($resources as $resource) {
            $this->markPushed($resource['path']);
        }
        
        return $response;
    }


    /**
     * Detect resource type from file extension
     */
    public function detectType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $this->contentTypeMap[$extension] ?? 'fetch';
    }

    /**
     * Detect resource type from MIME type
     */
    public function detectTypeFromMime(string $mimeType): string
    {
        // Extract base MIME type (without charset, etc.)
        $baseMime = explode(';', $mimeType)[0];
        $baseMime = trim($baseMime);
        
        return $this->mimeTypeMap[$baseMime] ?? 'fetch';
    }

    /**
     * Get default priority for resource type
     */
    protected function getDefaultPriority(string $type): int
    {
        return match ($type) {
            'style' => 32,      // CSS is high priority
            'script' => 24,     // JS is medium-high
            'font' => 20,       // Fonts are medium
            'image' => 8,       // Images are lower priority
            'document' => 32,   // Documents are high priority
            default => 16,      // Default priority
        };
    }

    /**
     * Prioritize resources by type and explicit priority
     */
    protected function prioritizeResources(array $resources): array
    {
        usort($resources, function($a, $b) {
            $priorityA = $a['priority'] ?? $this->getDefaultPriority($a['type'] ?? 'fetch');
            $priorityB = $b['priority'] ?? $this->getDefaultPriority($b['type'] ?? 'fetch');
            return $priorityB <=> $priorityA; // Higher priority first
        });
        
        return $resources;
    }

    /**
     * Check if path matches a pattern
     */
    protected function matchesPattern(string $path, string $pattern): bool
    {
        // Support glob-style patterns
        if (str_contains($pattern, '*')) {
            $regex = '/^' . str_replace(['*', '/'], ['.*', '\/'], $pattern) . '$/';
            return (bool) preg_match($regex, $path);
        }
        
        // Exact match or prefix match
        return $path === $pattern || str_starts_with($path, $pattern);
    }

    /**
     * Detect resources from HTML content
     */
    protected function detectResourcesFromHtml(string $html): array
    {
        $resources = [];
        
        // Detect CSS files (both stylesheet and preload)
        if (preg_match_all('/<link[^>]+href=["\']([^"\']+\.css(?:\?[^"\']*)?)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $path) {
                if ($this->isLocalResource($path)) {
                    $resources[] = [
                        'path' => $this->normalizePath($path),
                        'type' => 'style',
                        'priority' => 32,
                    ];
                }
            }
        }
        
        // Detect JavaScript files
        if (preg_match_all('/<script[^>]+src=["\']([^"\']+\.(?:js|mjs)(?:\?[^"\']*)?)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $path) {
                if ($this->isLocalResource($path)) {
                    $resources[] = [
                        'path' => $this->normalizePath($path),
                        'type' => 'script',
                        'priority' => 24,
                    ];
                }
            }
        }
        
        // Detect preload hints already in HTML
        if (preg_match_all('/<link[^>]+rel=["\']preload["\'][^>]+href=["\']([^"\']+)["\'][^>]*as=["\']([^"\']+)["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $path = $match[1];
                $type = $match[2];
                if ($this->isLocalResource($path)) {
                    $resources[] = [
                        'path' => $this->normalizePath($path),
                        'type' => $type,
                        'priority' => $this->getDefaultPriority($type),
                    ];
                }
            }
        }
        
        // Detect critical fonts (in @font-face or preload)
        if (preg_match_all('/url\(["\']?([^"\')\s]+\.(woff2?|ttf|otf|eot)(?:\?[^"\')\s]*)?)["\']?\)/i', $html, $matches)) {
            foreach ($matches[1] as $path) {
                if ($this->isLocalResource($path)) {
                    $resources[] = [
                        'path' => $this->normalizePath($path),
                        'type' => 'font',
                        'crossorigin' => 'anonymous',
                        'priority' => 20,
                    ];
                }
            }
        }
        
        // Detect critical images (hero images, above-the-fold)
        if (preg_match_all('/<img[^>]+(?:data-priority=["\']high["\']|class=["\'][^"\']*hero[^"\']*["\'])[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $path) {
                if ($this->isLocalResource($path)) {
                    $resources[] = [
                        'path' => $this->normalizePath($path),
                        'type' => 'image',
                        'priority' => 16,
                    ];
                }
            }
        }
        
        return $resources;
    }

    /**
     * Normalize resource path
     */
    protected function normalizePath(string $path): string
    {
        // Remove query string for deduplication but keep for actual push
        // Just ensure path starts with /
        if (!str_starts_with($path, '/') && !str_starts_with($path, 'http')) {
            $path = '/' . $path;
        }
        
        return $path;
    }

    /**
     * Check if resource is local (not external URL)
     */
    protected function isLocalResource(string $path): bool
    {
        // Skip external URLs
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '//')) {
            return false;
        }
        
        // Skip data URIs
        if (str_starts_with($path, 'data:')) {
            return false;
        }
        
        // Skip blob URLs
        if (str_starts_with($path, 'blob:')) {
            return false;
        }
        
        return true;
    }

    /**
     * Remove duplicate resources
     */
    protected function deduplicateResources(array $resources): array
    {
        $seen = [];
        $unique = [];
        
        foreach ($resources as $resource) {
            // Normalize path for comparison (remove query string)
            $path = $resource['path'];
            $normalizedPath = explode('?', $path)[0];
            
            if (!isset($seen[$normalizedPath])) {
                $seen[$normalizedPath] = true;
                $unique[] = $resource;
            }
        }
        
        return $unique;
    }

    /**
     * Clear all registered resources
     */
    public function clearResources(): void
    {
        $this->registeredResources = [];
    }

    /**
     * Clear all push rules
     */
    public function clearRules(): void
    {
        $this->pushRules = [];
    }

    /**
     * Clear pushed paths cache
     */
    public function clearPushedPaths(): void
    {
        $this->pushedPaths = [];
    }

    /**
     * Clear all state
     */
    public function reset(): void
    {
        $this->clearResources();
        $this->clearRules();
        $this->clearPushedPaths();
        $this->pushPromises = [];
    }

    /**
     * Get all registered resources
     */
    public function getRegisteredResources(): array
    {
        return $this->registeredResources;
    }

    /**
     * Get all push rules
     */
    public function getPushRules(): array
    {
        return $this->pushRules;
    }

    /**
     * Get all push promises
     */
    public function getPushPromises(): array
    {
        return $this->pushPromises;
    }

    /**
     * Check if server push is enabled
     */
    public function isEnabled(): bool
    {
        return $this->config->isServerPushEnabled();
    }

    /**
     * Create a push promise for a resource
     */
    public function createPushPromise(string $path, array $headers = []): array
    {
        return [
            'path' => $path,
            'type' => $this->detectType($path),
            'headers' => array_merge([
                ':method' => 'GET',
                ':path' => $path,
                ':scheme' => 'https',
            ], $headers),
        ];
    }

    /**
     * Record successful push
     */
    public function recordSuccessfulPush(int $bytes = 0): void
    {
        $this->stats['successful_pushes']++;
        $this->stats['bytes_pushed'] += $bytes;
    }

    /**
     * Record cancelled push
     */
    public function recordCancelledPush(): void
    {
        $this->stats['cancelled_pushes']++;
    }

    /**
     * Record failed push
     */
    public function recordFailedPush(): void
    {
        $this->stats['failed_pushes']++;
    }

    /**
     * Get statistics about push resources
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'registered_resources' => count($this->registeredResources),
            'push_rules' => count($this->pushRules),
            'pending_promises' => count(array_filter($this->pushPromises, fn($p) => $p->isPending())),
            'enabled' => $this->isEnabled(),
            'max_push_resources' => $this->maxPushResources,
            'auto_detect' => $this->autoDetect,
        ]);
    }

    /**
     * Get detailed statistics
     */
    public function getDetailedStats(): array
    {
        $promiseStats = [
            'pending' => 0,
            'sent' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'failed' => 0,
        ];
        
        foreach ($this->pushPromises as $promise) {
            $state = $promise->getState();
            if (isset($promiseStats[$state])) {
                $promiseStats[$state]++;
            }
        }
        
        return array_merge($this->getStats(), [
            'promise_states' => $promiseStats,
            'pushed_paths_count' => count($this->pushedPaths),
        ]);
    }
}
