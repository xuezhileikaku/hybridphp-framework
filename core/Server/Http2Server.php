<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server;

use Amp\Http\Server\HttpServer as AmpHttpServer;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\Server as AmpSocketServer;
use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\ServerTlsContext;
use HybridPHP\Core\Routing\RouterInterface;
use HybridPHP\Core\Container;
use HybridPHP\Core\MiddlewareInterface;
use HybridPHP\Core\Server\Http2\ServerPushManager;
use HybridPHP\Core\Server\Http2\Http2Config;
use HybridPHP\Core\Server\Http2\MultiplexingManager;
use HybridPHP\Core\Server\Http2\StreamManager;
use HybridPHP\Core\Server\Http2\FlowController;
use HybridPHP\Core\Server\Http2\StreamScheduler;
use Psr\Log\LoggerInterface;
use Amp\Future;
use function Amp\async;

/**
 * HTTP/2 Server Implementation
 * 
 * Provides HTTP/2 support with:
 * - Server Push capabilities
 * - Multiplexing support
 * - HPACK header compression (handled by AMPHP)
 * - TLS/SSL encryption (required for HTTP/2)
 */
class Http2Server extends AbstractServer
{
    protected ?AmpHttpServer $server = null;
    protected RouterInterface $router;
    protected Container $container;
    protected array $middleware = [];
    protected Http2Config $config;
    protected ?LoggerInterface $logger = null;
    protected bool $started = false;
    protected ServerPushManager $pushManager;
    protected MultiplexingManager $multiplexingManager;
    protected StreamManager $streamManager;
    protected FlowController $flowController;
    protected StreamScheduler $streamScheduler;
    
    protected array $stats = [
        'requests' => 0,
        'errors' => 0,
        'start_time' => 0,
        'connections' => 0,
        'http2_connections' => 0,
        'server_pushes' => 0,
        'streams_opened' => 0,
    ];

    public function __construct(
        RouterInterface $router,
        Container $container,
        array $config = []
    ) {
        $this->router = $router;
        $this->container = $container;
        $this->config = new Http2Config($config);
        $this->pushManager = new ServerPushManager($this->config);
        
        // Initialize multiplexing components
        $this->streamManager = new StreamManager($this->config);
        $this->multiplexingManager = new MultiplexingManager($this->streamManager, $this->config);
        $this->flowController = new FlowController($this->config->getInitialWindowSize());
        $this->streamScheduler = new StreamScheduler();

        if ($container->has(LoggerInterface::class)) {
            $this->logger = $container->get(LoggerInterface::class);
        }
    }


    /**
     * Start the HTTP/2 server
     */
    public function listen(): void
    {
        if ($this->started) {
            return;
        }

        try {
            $this->stats['start_time'] = time();
            
            // Create TLS context for HTTP/2 (ALPN negotiation)
            $tlsContext = $this->createTlsContext();
            $bindContext = (new BindContext())->withTlsContext($tlsContext);
            
            // Create socket server with TLS
            $address = sprintf('%s:%d', $this->config->getHost(), $this->config->getPort());
            $socketServer = AmpSocketServer::listen($address, $bindContext);
            
            // Create request handler
            $handler = new CallableRequestHandler([$this, 'handleRequest']);
            
            // Create HTTP server with HTTP/2 support
            $this->server = new AmpHttpServer(
                [$socketServer],
                $handler,
                $this->logger
            );
            
            // Start server
            async(function() {
                $this->server->start();
                $this->started = true;
                
                $protocol = $this->config->isHttp2Enabled() ? 'HTTP/2' : 'HTTPS';
                
                if ($this->logger) {
                    $this->logger->info("{$protocol} Server started on {$this->config->getHost()}:{$this->config->getPort()}");
                }
                
                echo "{$protocol} Server listening on https://{$this->config->getHost()}:{$this->config->getPort()}\n";
            });
            
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error("Failed to start HTTP/2 Server: " . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Stop the HTTP/2 server
     */
    public function stop(): void
    {
        if (!$this->started || !$this->server) {
            return;
        }

        try {
            async(function() {
                $this->server->stop();
                $this->started = false;
                
                if ($this->logger) {
                    $this->logger->info("HTTP/2 Server stopped");
                }
                
                echo "HTTP/2 Server stopped\n";
            });
            
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error("Error stopping HTTP/2 Server: " . $e->getMessage());
            }
        }
    }

    /**
     * Stop the server asynchronously
     */
    public function stopAsync(): Future
    {
        return async(function() {
            if (!$this->started || !$this->server) {
                return;
            }

            try {
                $this->server->stop();
                $this->started = false;
                
                if ($this->logger) {
                    $this->logger->info("HTTP/2 Server stopped (async)");
                }
                
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->error("Error stopping HTTP/2 Server (async): " . $e->getMessage());
                }
            }
        });
    }

    /**
     * Create TLS context for HTTP/2 with ALPN
     */
    protected function createTlsContext(): ServerTlsContext
    {
        $certPath = $this->config->getCertPath();
        $keyPath = $this->config->getKeyPath();
        
        if (!file_exists($certPath)) {
            throw new \RuntimeException("SSL certificate not found: {$certPath}");
        }
        
        if (!file_exists($keyPath)) {
            throw new \RuntimeException("SSL private key not found: {$keyPath}");
        }
        
        $certificate = new Certificate($certPath, $keyPath);
        
        $tlsContext = (new ServerTlsContext())
            ->withDefaultCertificate($certificate)
            ->withMinimumVersion($this->getTlsVersion($this->config->getMinTlsVersion()))
            ->withApplicationLayerProtocols($this->getAlpnProtocols());
        
        // Add CA certificate if provided
        $caPath = $this->config->getCaPath();
        if ($caPath && file_exists($caPath)) {
            $tlsContext = $tlsContext->withCaPath($caPath);
        }
        
        return $tlsContext;
    }


    /**
     * Get TLS version constant
     */
    protected function getTlsVersion(string $version): int
    {
        return match ($version) {
            'TLSv1.0' => STREAM_CRYPTO_METHOD_TLSv1_0_SERVER,
            'TLSv1.1' => STREAM_CRYPTO_METHOD_TLSv1_1_SERVER,
            'TLSv1.2' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER,
            'TLSv1.3' => defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER') 
                ? STREAM_CRYPTO_METHOD_TLSv1_3_SERVER 
                : STREAM_CRYPTO_METHOD_TLSv1_2_SERVER,
            default => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER,
        };
    }

    /**
     * Get ALPN protocols for HTTP/2 negotiation
     */
    protected function getAlpnProtocols(): array
    {
        $protocols = [];
        
        if ($this->config->isHttp2Enabled()) {
            $protocols[] = 'h2'; // HTTP/2 over TLS
        }
        
        // Always include HTTP/1.1 as fallback
        $protocols[] = 'http/1.1';
        
        return $protocols;
    }

    /**
     * Handle HTTP request with HTTP/2 features
     */
    public function handleRequest(Request $request): Response
    {
        $this->stats['requests']++;
        $this->stats['connections']++;
        
        // Track HTTP/2 connections
        $protocol = $request->getProtocolVersion();
        if (str_starts_with($protocol, '2')) {
            $this->stats['http2_connections']++;
            $this->stats['streams_opened']++;
        }
        
        try {
            $startTime = microtime(true);
            
            // Extract request information
            $method = $request->getMethod();
            $uri = $request->getUri()->getPath();
            $query = $request->getUri()->getQuery();
            
            if ($query) {
                $uri .= '?' . $query;
            }
            
            // Log request with protocol version
            if ($this->logger) {
                $this->logger->debug("HTTP/{$protocol} Request: {$method} {$uri}");
            }
            
            // Dispatch route
            [$status, $handler, $params] = $this->router->dispatch($method, $uri);
            
            switch ($status) {
                case 200:
                    $response = $this->executeHandler($handler, $request, $params);
                    break;
                    
                case 404:
                    $response = $this->createErrorResponse(404, 'Not Found');
                    break;
                    
                case 405:
                    $response = $this->createErrorResponse(405, 'Method Not Allowed', [
                        'Allow' => implode(', ', $params)
                    ]);
                    break;
                    
                default:
                    $response = $this->createErrorResponse(500, 'Internal Server Error');
                    break;
            }
            
            // Process server push for HTTP/2 connections
            if (str_starts_with($protocol, '2') && $this->config->isServerPushEnabled()) {
                $response = $this->processServerPush($request, $response);
            }
            
            // Add performance and protocol headers
            $processingTime = microtime(true) - $startTime;
            $response = $response->withHeader('X-Response-Time', number_format($processingTime * 1000, 2) . 'ms');
            $response = $response->withHeader('X-Powered-By', 'HybridPHP/1.0');
            $response = $response->withHeader('X-Protocol', 'HTTP/' . $protocol);
            
            return $response;
            
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            
            if ($this->logger) {
                $this->logger->error("Request handling error: " . $e->getMessage(), [
                    'exception' => $e,
                    'request' => [
                        'method' => $request->getMethod(),
                        'uri' => (string) $request->getUri()
                    ]
                ]);
            }
            
            return $this->createErrorResponse(500, 'Internal Server Error');
            
        } finally {
            $this->stats['connections']--;
        }
    }

    /**
     * Process server push for HTTP/2 responses
     */
    protected function processServerPush(Request $request, Response $response): Response
    {
        $pushResources = $this->pushManager->getPushResources($request, $response);
        
        if (empty($pushResources)) {
            return $response;
        }
        
        // Add Link headers for server push
        $linkHeaders = [];
        foreach ($pushResources as $resource) {
            $linkHeaders[] = $this->pushManager->createLinkHeader($resource);
            $this->stats['server_pushes']++;
        }
        
        if (!empty($linkHeaders)) {
            $response = $response->withHeader('Link', implode(', ', $linkHeaders));
        }
        
        return $response;
    }


    /**
     * Execute route handler
     */
    protected function executeHandler($handler, Request $request, array $params): Response
    {
        // Handle different handler types
        if (is_callable($handler)) {
            return $this->executeCallable($handler, $request, $params);
        }
        
        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            return $this->executeControllerMethod($class, $method, $request, $params);
        }
        
        if (is_string($handler)) {
            return $this->executeControllerAction($handler, $request, $params);
        }
        
        throw new \InvalidArgumentException('Invalid route handler');
    }

    /**
     * Execute callable handler
     */
    protected function executeCallable(callable $handler, Request $request, array $params): Response
    {
        $result = $handler($request, $params);
        
        if ($result instanceof Response) {
            return $result;
        }
        
        if (is_string($result)) {
            return new Response(200, ['content-type' => 'text/html'], $result);
        }
        
        if (is_array($result) || is_object($result)) {
            return new Response(200, ['content-type' => 'application/json'], json_encode($result));
        }
        
        return new Response(200, ['content-type' => 'text/plain'], (string) $result);
    }

    /**
     * Execute controller method
     */
    protected function executeControllerMethod(string $class, string $method, Request $request, array $params): Response
    {
        // Resolve controller from container
        if ($this->container->has($class)) {
            $controller = $this->container->get($class);
        } else {
            $controller = new $class();
        }
        
        if (!method_exists($controller, $method)) {
            throw new \BadMethodCallException("Method {$method} not found in {$class}");
        }
        
        $result = $controller->$method($request, $params);
        
        return $this->formatResponse($result);
    }

    /**
     * Execute controller action (string format)
     */
    protected function executeControllerAction(string $handler, Request $request, array $params): Response
    {
        if (strpos($handler, '@') !== false) {
            [$class, $method] = explode('@', $handler, 2);
            return $this->executeControllerMethod($class, $method, $request, $params);
        }
        
        throw new \InvalidArgumentException("Invalid controller action format: {$handler}");
    }

    /**
     * Format response
     */
    protected function formatResponse($result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        
        if (is_string($result)) {
            return new Response(200, ['content-type' => 'text/html'], $result);
        }
        
        if (is_array($result) || is_object($result)) {
            return new Response(200, ['content-type' => 'application/json'], json_encode($result));
        }
        
        return new Response(200, ['content-type' => 'text/plain'], (string) $result);
    }

    /**
     * Create error response
     */
    protected function createErrorResponse(int $status, string $message, array $headers = []): Response
    {
        $body = json_encode([
            'error' => $message,
            'status' => $status,
            'timestamp' => date('c')
        ]);
        
        $defaultHeaders = ['content-type' => 'application/json'];
        $headers = array_merge($defaultHeaders, $headers);
        
        return new Response($status, $headers, $body);
    }

    /**
     * Add middleware
     */
    public function addMiddleware(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * Get server push manager
     */
    public function getPushManager(): ServerPushManager
    {
        return $this->pushManager;
    }

    /**
     * Get multiplexing manager
     */
    public function getMultiplexingManager(): MultiplexingManager
    {
        return $this->multiplexingManager;
    }

    /**
     * Get stream manager
     */
    public function getStreamManager(): StreamManager
    {
        return $this->streamManager;
    }

    /**
     * Get flow controller
     */
    public function getFlowController(): FlowController
    {
        return $this->flowController;
    }

    /**
     * Get stream scheduler
     */
    public function getStreamScheduler(): StreamScheduler
    {
        return $this->streamScheduler;
    }

    /**
     * Register a resource for server push
     */
    public function registerPushResource(string $path, string $type, array $options = []): void
    {
        $this->pushManager->registerResource($path, $type, $options);
    }

    /**
     * Get server statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'uptime' => $this->started ? time() - $this->stats['start_time'] : 0,
            'status' => $this->started ? 'running' : 'stopped',
            'protocol' => $this->config->isHttp2Enabled() ? 'HTTP/2' : 'HTTPS',
            'config' => $this->config->toArray(),
            'multiplexing' => $this->multiplexingManager->getStats(),
            'flow_control' => $this->flowController->getStats(),
            'scheduler' => $this->streamScheduler->getStats(),
        ]);
    }

    /**
     * Check server health
     */
    public function checkHealth(): array
    {
        return [
            'name' => 'HTTP/2 Server',
            'status' => $this->started ? 'healthy' : 'stopped',
            'protocol' => $this->config->isHttp2Enabled() ? 'HTTP/2' : 'HTTPS',
            'uptime' => $this->started ? time() - $this->stats['start_time'] : 0,
            'connections' => $this->stats['connections'],
            'http2_connections' => $this->stats['http2_connections'],
            'requests' => $this->stats['requests'],
            'errors' => $this->stats['errors'],
            'server_pushes' => $this->stats['server_pushes'],
            'streams_opened' => $this->stats['streams_opened'],
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ];
    }

    /**
     * Get server configuration
     */
    public function getConfig(): Http2Config
    {
        return $this->config;
    }

    /**
     * Check if server is started
     */
    public function isStarted(): bool
    {
        return $this->started;
    }
}
