<?php
namespace HybridPHP\Core\Server;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;
use HybridPHP\Core\Routing\RouterInterface;
use HybridPHP\Core\Container;
use HybridPHP\Core\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Amp\Future;
use function Amp\async;
use function Amp\delay;

/**
 * Hybrid HTTP Server - Combines Workerman multi-process with AMPHP async capabilities
 */
class HybridHttpServer extends AbstractServer
{
    protected ?Worker $worker = null;
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
        'connections' => 0,
        'workers' => 0
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
            'worker_count' => 4,
            'max_connections' => 1000,
            'max_request' => 10000,
            'user' => '',
            'group' => '',
            'reusePort' => false,
            'transport' => 'tcp',
            'context' => [],
            'protocol' => 'http',
        ], $config);

        if ($container->has(LoggerInterface::class)) {
            $this->logger = $container->get(LoggerInterface::class);
        }

        $this->initializeWorker();
    }

    /**
     * Initialize Workerman worker
     */
    protected function initializeWorker(): void
    {
        // Use http:// protocol for HTTP server
        $listen = 'http://' . $this->config['host'] . ':' . $this->config['port'];
        
        $this->worker = new Worker($listen, $this->config['context']);
        $this->worker->count = $this->config['worker_count'];
        $this->worker->name = 'HybridPHP-HTTP';
        
        if ($this->config['user']) {
            $this->worker->user = $this->config['user'];
        }
        
        if ($this->config['group']) {
            $this->worker->group = $this->config['group'];
        }
        
        $this->worker->reusePort = $this->config['reusePort'];
        
        // Set up event handlers
        $this->setupEventHandlers();
    }

    /**
     * Setup Workerman event handlers
     */
    protected function setupEventHandlers(): void
    {
        // Worker start event
        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        
        // Worker stop event
        $this->worker->onWorkerStop = [$this, 'onWorkerStop'];
        
        // Connection event
        $this->worker->onConnect = [$this, 'onConnect'];
        
        // Message (HTTP request) event
        $this->worker->onMessage = [$this, 'onMessage'];
        
        // Close connection event
        $this->worker->onClose = [$this, 'onClose'];
        
        // Error event
        $this->worker->onError = [$this, 'onError'];
    }

    public function listen(): void
    {
        if ($this->started) {
            return;
        }

        try {
            $this->stats['start_time'] = time();
            $this->started = true;
            
            if ($this->logger) {
                $this->logger->info("Hybrid HTTP Server starting on {$this->config['host']}:{$this->config['port']} with {$this->config['worker_count']} workers");
            }
            
            echo "Hybrid HTTP Server starting on {$this->config['host']}:{$this->config['port']} with {$this->config['worker_count']} workers\n";
            
            // Start Workerman
            Worker::runAll();
            
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error("Failed to start Hybrid HTTP Server: " . $e->getMessage());
            }
            throw $e;
        }
    }

    public function stop(): void
    {
        if (!$this->started) {
            return;
        }

        try {
            Worker::stopAll();
            $this->started = false;
            
            if ($this->logger) {
                $this->logger->info("Hybrid HTTP Server stopped");
            }
            
            echo "Hybrid HTTP Server stopped\n";
            
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error("Error stopping Hybrid HTTP Server: " . $e->getMessage());
            }
        }
    }

    public function stopAsync(): Future
    {
        return async(function() {
            $this->stop();
        });
    }

    /**
     * Worker start event handler
     */
    public function onWorkerStart(Worker $worker): void
    {
        $this->stats['workers']++;
        
        if ($this->logger) {
            $this->logger->info("Worker {$worker->id} started (PID: " . posix_getpid() . ")");
        }
        
        // Initialize AMPHP event loop in each worker process
        $this->initializeAmphpInWorker();
    }

    /**
     * Worker stop event handler
     */
    public function onWorkerStop(Worker $worker): void
    {
        $this->stats['workers']--;
        
        if ($this->logger) {
            $this->logger->info("Worker {$worker->id} stopped");
        }
    }

    /**
     * Connection event handler
     */
    public function onConnect(TcpConnection $connection): void
    {
        $this->stats['connections']++;
        
        if ($this->logger) {
            $this->logger->debug("New connection from {$connection->getRemoteIp()}:{$connection->getRemotePort()}");
        }
    }

    /**
     * Message (HTTP request) event handler
     */
    public function onMessage(TcpConnection $connection, WorkermanRequest $request): void
    {
        $this->stats['requests']++;
        
        try {
            $startTime = microtime(true);
            
            // Extract request information
            $method = $request->method();
            $uri = $request->uri();
            
            // Log request
            if ($this->logger) {
                $this->logger->debug("HTTP Request: {$method} {$uri} from {$connection->getRemoteIp()}");
            }
            
            // Process request asynchronously using AMPHP
            $this->processRequestAsync($connection, $request, $startTime);
            
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            
            if ($this->logger) {
                $this->logger->error("Request handling error: " . $e->getMessage(), [
                    'exception' => $e,
                    'request' => [
                        'method' => $request->method(),
                        'uri' => $request->uri()
                    ]
                ]);
            }
            
            $this->sendErrorResponse($connection, 500, 'Internal Server Error');
        }
    }

    /**
     * Close connection event handler
     */
    public function onClose(TcpConnection $connection): void
    {
        $this->stats['connections']--;
        
        if ($this->logger) {
            $this->logger->debug("Connection closed from {$connection->getRemoteIp()}:{$connection->getRemotePort()}");
        }
    }

    /**
     * Error event handler
     */
    public function onError(TcpConnection $connection, int $code, string $msg): void
    {
        $this->stats['errors']++;
        
        if ($this->logger) {
            $this->logger->error("Connection error: {$msg} (Code: {$code})");
        }
    }

    /**
     * Process request asynchronously
     */
    protected function processRequestAsync(TcpConnection $connection, WorkermanRequest $request, float $startTime): void
    {
        try {
            // Dispatch route
            [$status, $handler, $params] = $this->router->dispatch($request->method(), $request->path());
            
            switch ($status) {
                case 200:
                    $future = $this->executeHandlerAsync($handler, $request, $params);
                    $response = $future->await();
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
            $response->withHeader('X-Response-Time', number_format($processingTime * 1000, 2) . 'ms');
            $response->withHeader('X-Powered-By', 'HybridPHP/1.0');
            $response->withHeader('X-Worker-ID', $this->worker->id ?? 'unknown');
            
            // Send response
            $connection->send($response);
            
        } catch (\Throwable $e) {
            $this->stats['errors']++;
            
            // Output error to console for debugging
            echo "Error: " . $e->getMessage() . "\n";
            echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
            echo "Trace: " . $e->getTraceAsString() . "\n";
            
            if ($this->logger) {
                $this->logger->error("Async request processing error: " . $e->getMessage());
            }
            
            $this->sendErrorResponse($connection, 500, 'Internal Server Error');
        }
    }

    /**
     * Execute handler asynchronously
     */
    protected function executeHandlerAsync($handler, WorkermanRequest $request, array $params): Future
    {
        return async(function() use ($handler, $request, $params) {
            // Extract actual handler from route data if needed
            $actualHandler = $handler;
            if (is_array($handler) && isset($handler['handler'])) {
                $actualHandler = $handler['handler'];
            }
            
            // Handle different handler types
            if (is_callable($actualHandler)) {
                return $this->executeCallableAsync($actualHandler, $request, $params);
            }
            
            if (is_array($actualHandler) && count($actualHandler) === 2) {
                [$class, $method] = $actualHandler;
                $future = $this->executeControllerMethodAsync($class, $method, $request, $params);
                return $future->await();
            }
            
            if (is_string($actualHandler)) {
                $future = $this->executeControllerActionAsync($actualHandler, $request, $params);
                return $future->await();
            }
            
            throw new \InvalidArgumentException('Invalid route handler');
        });
    }

    /**
     * Execute callable handler asynchronously
     */
    protected function executeCallableAsync(callable $handler, WorkermanRequest $request, array $params): WorkermanResponse
    {
        $result = $handler($request, $params);
        
        // If result is a Future/Promise, await it
        if ($result instanceof Future) {
            $result = $result->await();
        }
        
        return $this->formatWorkermanResponse($result);
    }

    /**
     * Execute controller method asynchronously
     */
    protected function executeControllerMethodAsync(string $class, string $method, WorkermanRequest $request, array $params): Future
    {
        return async(function() use ($class, $method, $request, $params) {
            // Resolve controller from container
            if ($this->container->has($class)) {
                $controller = $this->container->get($class);
            } else {
                $controller = new $class();
            }
            
            if (!method_exists($controller, $method)) {
                throw new \BadMethodCallException("Method {$method} not found in {$class}");
            }
            
            // Convert WorkermanRequest to HybridPHP Request
            $hybridRequest = $this->convertToHybridRequest($request);
            
            $result = $controller->$method($hybridRequest, $params);
            
            // If result is a Future, await it
            if ($result instanceof Future) {
                $result = $result->await();
            }
            
            return $this->formatWorkermanResponse($result);
        });
    }

    /**
     * Execute controller action asynchronously
     */
    protected function executeControllerActionAsync(string $handler, WorkermanRequest $request, array $params): Future
    {
        return async(function() use ($handler, $request, $params) {
            if (strpos($handler, '@') !== false) {
                [$class, $method] = explode('@', $handler, 2);
                $future = $this->executeControllerMethodAsync($class, $method, $request, $params);
                return $future->await();
            }
            
            throw new \InvalidArgumentException("Invalid controller action format: {$handler}");
        });
    }

    /**
     * Format response for Workerman
     */
    protected function formatWorkermanResponse($result): WorkermanResponse
    {
        if ($result instanceof WorkermanResponse) {
            return $result;
        }
        
        // Handle HybridPHP Response
        if ($result instanceof \HybridPHP\Core\Http\Response) {
            return new WorkermanResponse(
                $result->getStatusCode(),
                $result->getHeaders(),
                (string) $result->getBody()
            );
        }
        
        if (is_string($result)) {
            return new WorkermanResponse(200, ['Content-Type' => 'text/html'], $result);
        }
        
        if (is_array($result) || is_object($result)) {
            return new WorkermanResponse(200, ['Content-Type' => 'application/json'], json_encode($result));
        }
        
        return new WorkermanResponse(200, ['Content-Type' => 'text/plain'], (string) $result);
    }

    /**
     * Create error response
     */
    protected function createErrorResponse(int $status, string $message, array $headers = []): WorkermanResponse
    {
        $body = json_encode([
            'error' => $message,
            'status' => $status,
            'timestamp' => date('c')
        ]);
        
        $defaultHeaders = ['Content-Type' => 'application/json'];
        $headers = array_merge($defaultHeaders, $headers);
        
        return new WorkermanResponse($status, $headers, $body);
    }

    /**
     * Send error response
     */
    protected function sendErrorResponse(TcpConnection $connection, int $status, string $message): void
    {
        $response = $this->createErrorResponse($status, $message);
        $connection->send($response);
    }

    /**
     * Initialize AMPHP in worker process
     */
    protected function initializeAmphpInWorker(): void
    {
        // Initialize AMPHP event loop integration in each worker
        // This allows us to use async/await within the Workerman context
        
        if ($this->logger) {
            $this->logger->debug("AMPHP event loop initialized in worker " . ($this->worker->id ?? 'unknown'));
        }
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
            'config' => $this->config,
            'worker_count' => $this->config['worker_count'],
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ]);
    }

    /**
     * Check server health
     */
    public function checkHealth(): array
    {
        return [
            'name' => 'Hybrid HTTP Server (Workerman + AMPHP)',
            'status' => $this->started ? 'healthy' : 'stopped',
            'uptime' => $this->started ? time() - $this->stats['start_time'] : 0,
            'connections' => $this->stats['connections'],
            'requests' => $this->stats['requests'],
            'errors' => $this->stats['errors'],
            'workers' => $this->stats['workers'],
            'worker_count' => $this->config['worker_count'],
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ];
    }

    /**
     * Convert WorkermanRequest to HybridPHP Request
     */
    protected function convertToHybridRequest(WorkermanRequest $workermanRequest): \HybridPHP\Core\Http\Request
    {
        $scheme = 'http';
        $host = $workermanRequest->host() ?? 'localhost';
        $path = $workermanRequest->path() ?? '/';
        $query = $workermanRequest->queryString() ?? '';
        
        $uriString = "{$scheme}://{$host}{$path}";
        if ($query) {
            $uriString .= "?{$query}";
        }
        
        $uri = new \HybridPHP\Core\Http\Uri($uriString);
        
        $request = new \HybridPHP\Core\Http\Request(
            $workermanRequest->method(),
            $uri,
            $workermanRequest->header() ?? [],
            $workermanRequest->rawBody() ?? '',
            '1.1',
            $_SERVER
        );
        
        // Set query params
        $request = $request->withQueryParams($workermanRequest->get() ?? []);
        
        // Set parsed body for POST requests
        $parsedBody = $workermanRequest->post();
        if (!empty($parsedBody)) {
            $request = $request->withParsedBody($parsedBody);
        } elseif ($request->isJson()) {
            $jsonBody = json_decode($workermanRequest->rawBody() ?? '', true);
            if (is_array($jsonBody)) {
                $request = $request->withParsedBody($jsonBody);
            }
        }
        
        // Set cookies
        $request = $request->withCookieParams($workermanRequest->cookie() ?? []);
        
        return $request;
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

    /**
     * Get worker instance
     */
    public function getWorker(): ?Worker
    {
        return $this->worker;
    }

    /**
     * Reload workers (graceful restart)
     */
    public function reload(): void
    {
        if ($this->worker) {
            Worker::reloadAllWorkers();
            
            if ($this->logger) {
                $this->logger->info("Hybrid HTTP Server workers reloaded");
            }
        }
    }

    /**
     * Get worker statistics
     */
    public function getWorkerStats(): array
    {
        if (!$this->worker) {
            return [];
        }

        return [
            'worker_id' => $this->worker->id ?? 'unknown',
            'worker_pid' => posix_getpid(),
            'worker_name' => $this->worker->name,
            'worker_count' => $this->worker->count,
            'connections' => $this->worker->connections ?? [],
            'status' => $this->started ? 'running' : 'stopped'
        ];
    }
}