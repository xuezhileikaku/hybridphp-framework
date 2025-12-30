<?php
namespace HybridPHP\Core\Server;

use Amp\Http\Server\HttpServer as AmpHttpServer;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Middleware\StackedMiddleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\Server as AmpSocketServer;
use Amp\Socket\SocketAddress;
use HybridPHP\Core\Routing\RouterInterface;
use HybridPHP\Core\Container;
use HybridPHP\Core\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Amp\Future;
use function Amp\async;
use function Amp\delay;

class AmphpHttpServer extends AbstractServer
{
    protected ?AmpHttpServer $server = null;
    protected RouterInterface $router;
    protected Container $container;
    protected array $middleware = [];
    protected array $config;
    protected ?LoggerInterface $logger = null;
    protected bool $started = false;
    protected array $stats = [
        'requests' => 0,
        'errors' => 0,
        'start_time' => 0,
        'connections' => 0
    ];

    public function __construct(
        RouterInterface $router,
        Container $container,
        array $config = []
    ) {
        $this->router = $router;
        $this->container = $container;
        $this->config = array_merge([
            'host' => '0.0.0.0',
            'port' => 8080,
            'max_connections' => 1000,
            'connection_timeout' => 30,
            'request_timeout' => 30,
            'body_size_limit' => 128 * 1024 * 1024, // 128MB
            'enable_compression' => true,
            'enable_http2' => false,
        ], $config);

        if ($container->has(LoggerInterface::class)) {
            $this->logger = $container->get(LoggerInterface::class);
        }
    }

    public function listen(): void
    {
        if ($this->started) {
            return;
        }

        try {
            $this->stats['start_time'] = time();
            
            // Create socket server
            $address = new SocketAddress($this->config['host'], $this->config['port']);
            $socketServer = AmpSocketServer::listen($address);
            
            // Create request handler
            $handler = new CallableRequestHandler([$this, 'handleRequest']);
            
            // Apply middleware stack if any
            if (!empty($this->middleware)) {
                $handler = new StackedMiddleware($handler, ...$this->middleware);
            }
            
            // Create HTTP server
            $this->server = new AmpHttpServer(
                [$socketServer],
                $handler,
                $this->logger
            );
            
            // Configure server options
            $this->configureServer();
            
            // Start server
            async(function() {
                $this->server->start();
                $this->started = true;
                
                if ($this->logger) {
                    $this->logger->info("AMPHP HTTP Server started on {$this->config['host']}:{$this->config['port']}");
                }
                
                echo "AMPHP HTTP Server listening on {$this->config['host']}:{$this->config['port']}\n";
            });
            
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error("Failed to start AMPHP HTTP Server: " . $e->getMessage());
            }
            throw $e;
        }
    }

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
                    $this->logger->info("AMPHP HTTP Server stopped");
                }
                
                echo "AMPHP HTTP Server stopped\n";
            });
            
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error("Error stopping AMPHP HTTP Server: " . $e->getMessage());
            }
        }
    }

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
                    $this->logger->info("AMPHP HTTP Server stopped (async)");
                }
                
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->error("Error stopping AMPHP HTTP Server (async): " . $e->getMessage());
                }
            }
        });
    }

    /**
     * Handle HTTP request
     */
    public function handleRequest(Request $request): Response
    {
        $this->stats['requests']++;
        $this->stats['connections']++;
        
        try {
            $startTime = microtime(true);
            
            // Extract request information
            $method = $request->getMethod();
            $uri = $request->getUri()->getPath();
            $query = $request->getUri()->getQuery();
            
            if ($query) {
                $uri .= '?' . $query;
            }
            
            // Log request
            if ($this->logger) {
                $this->logger->debug("HTTP Request: {$method} {$uri}");
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
            
            // Add performance headers
            $processingTime = microtime(true) - $startTime;
            $response = $response->withHeader('X-Response-Time', number_format($processingTime * 1000, 2) . 'ms');
            $response = $response->withHeader('X-Powered-By', 'HybridPHP/1.0');
            
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
     * Configure server options
     */
    protected function configureServer(): void
    {
        // Server configuration would be applied here
        // AMPHP v3 might have different configuration methods
    }

    /**
     * Add middleware
     */
    public function addMiddleware(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * Get server statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'uptime' => $this->started ? time() - $this->stats['start_time'] : 0,
            'status' => $this->started ? 'running' : 'stopped',
            'config' => $this->config
        ]);
    }

    /**
     * Check server health
     */
    public function checkHealth(): array
    {
        return [
            'name' => 'AMPHP HTTP Server',
            'status' => $this->started ? 'healthy' : 'stopped',
            'uptime' => $this->started ? time() - $this->stats['start_time'] : 0,
            'connections' => $this->stats['connections'],
            'requests' => $this->stats['requests'],
            'errors' => $this->stats['errors'],
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ];
    }

    /**
     * Get server configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Update server configuration
     */
    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Check if server is started
     */
    public function isStarted(): bool
    {
        return $this->started;
    }
}
