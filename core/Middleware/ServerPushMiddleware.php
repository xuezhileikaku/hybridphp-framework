<?php

declare(strict_types=1);

namespace HybridPHP\Core\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use HybridPHP\Core\Server\Http2\ServerPushManager;
use Psr\Log\LoggerInterface;

/**
 * Server Push Middleware
 * 
 * Automatically adds HTTP/2 Server Push Link headers to responses.
 * Supports:
 * - Automatic resource detection from HTML
 * - Manual resource registration
 * - Push rules based on URL patterns
 * - Cache-aware pushing (avoids duplicate pushes)
 * 
 * @see RFC 7540 Section 8.2 - Server Push
 */
class ServerPushMiddleware implements Middleware
{
    private ServerPushManager $pushManager;
    private ?LoggerInterface $logger;
    private array $options;

    public function __construct(
        ServerPushManager $pushManager,
        ?LoggerInterface $logger = null,
        array $options = []
    ) {
        $this->pushManager = $pushManager;
        $this->logger = $logger;
        $this->options = array_merge([
            'enabled' => true,
            'only_html' => false,
            'track_cookies' => true,
            'cookie_name' => '_pushed',
            'cookie_ttl' => 3600,
        ], $options);
    }

    /**
     * Handle the request
     */
    public function handleRequest(Request $request, RequestHandler $handler): Response
    {
        // Skip if disabled or not HTTP/2
        if (!$this->options['enabled'] || !$this->isHttp2Request($request)) {
            return $handler->handleRequest($request);
        }

        // Process the request
        $response = $handler->handleRequest($request);
        
        // Skip if push is disabled
        if (!$this->pushManager->isEnabled()) {
            return $response;
        }
        
        // Skip non-successful responses
        $status = $response->getStatus();
        if ($status < 200 || $status >= 300) {
            return $response;
        }
        
        // Skip if only_html is enabled and response is not HTML
        if ($this->options['only_html']) {
            $contentType = $response->getHeader('content-type') ?? '';
            if (!str_contains($contentType, 'text/html')) {
                return $response;
            }
        }
        
        try {
            // Get push resources
            $resources = $this->pushManager->getPushResources($request, $response);
            
            if (empty($resources)) {
                return $response;
            }
            
            // Add Link headers for server push
            $linkHeaders = $this->pushManager->createLinkHeaders($resources);
            
            if (!empty($linkHeaders)) {
                // Combine with existing Link headers
                $existingLink = $response->getHeader('Link');
                if ($existingLink) {
                    $linkHeaders = array_merge([$existingLink], $linkHeaders);
                }
                
                $response = $response->withHeader('Link', implode(', ', $linkHeaders));
                
                // Track pushed resources
                foreach ($resources as $resource) {
                    $this->pushManager->markPushed($resource['path']);
                }
                
                // Set cookie to track pushed resources (if enabled)
                if ($this->options['track_cookies']) {
                    $response = $this->addPushedCookie($response, $resources);
                }
                
                // Log push activity
                if ($this->logger) {
                    $this->logger->debug('Server Push: Added ' . count($resources) . ' resources', [
                        'path' => $request->getUri()->getPath(),
                        'resources' => array_column($resources, 'path'),
                    ]);
                }
            }
            
        } catch (\Throwable $e) {
            // Log error but don't fail the request
            if ($this->logger) {
                $this->logger->error('Server Push error: ' . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        }
        
        return $response;
    }

    /**
     * Check if request is HTTP/2
     */
    protected function isHttp2Request(Request $request): bool
    {
        $protocol = $request->getProtocolVersion();
        return str_starts_with($protocol, '2');
    }

    /**
     * Add cookie to track pushed resources
     */
    protected function addPushedCookie(Response $response, array $resources): Response
    {
        $paths = array_column($resources, 'path');
        $cookieValue = base64_encode(json_encode($paths));
        
        $cookie = sprintf(
            '%s=%s; Path=/; Max-Age=%d; SameSite=Strict; Secure; HttpOnly',
            $this->options['cookie_name'],
            $cookieValue,
            $this->options['cookie_ttl']
        );
        
        return $response->withAddedHeader('Set-Cookie', $cookie);
    }

    /**
     * Get the push manager
     */
    public function getPushManager(): ServerPushManager
    {
        return $this->pushManager;
    }

    /**
     * Enable/disable the middleware
     */
    public function setEnabled(bool $enabled): void
    {
        $this->options['enabled'] = $enabled;
    }

    /**
     * Check if middleware is enabled
     */
    public function isEnabled(): bool
    {
        return $this->options['enabled'];
    }
}
