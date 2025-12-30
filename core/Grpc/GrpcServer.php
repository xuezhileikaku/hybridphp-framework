<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc;

use Amp\Future;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket;
use Amp\ByteStream\ReadableBuffer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use HybridPHP\Core\Grpc\Protobuf\Codec;
use HybridPHP\Core\Grpc\Discovery\ServiceDiscoveryInterface;
use HybridPHP\Core\Grpc\LoadBalancer\LoadBalancerInterface;
use function Amp\async;

/**
 * Async gRPC Server implementation using AMPHP
 * 
 * Implements gRPC over HTTP/2 protocol
 */
class GrpcServer implements GrpcInterface, RequestHandler
{
    /** @var array<string, object> */
    protected array $services = [];
    
    /** @var array<string, array<string, array>> */
    protected array $methodDescriptors = [];
    
    protected LoggerInterface $logger;
    protected array $config;
    protected ?HttpServer $httpServer = null;
    protected array $interceptors = [];
    protected ?ServiceDiscoveryInterface $serviceDiscovery = null;
    protected ?LoadBalancerInterface $loadBalancer = null;

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'host' => '0.0.0.0',
            'port' => 50051,
            'maxConcurrentStreams' => 100,
            'maxMessageSize' => 4 * 1024 * 1024, // 4MB
            'keepaliveTime' => 7200,
            'keepaliveTimeout' => 20,
            'compression' => 'gzip',
            'reflection' => true,
            'tls' => null,
        ], $config);
        
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Start the gRPC server
     */
    public function start(): Future
    {
        return async(function () {
            $this->logger->info('Starting gRPC server', [
                'host' => $this->config['host'],
                'port' => $this->config['port'],
            ]);

            $bindAddress = sprintf('%s:%d', $this->config['host'], $this->config['port']);
            
            // Create socket server
            if ($this->config['tls']) {
                $context = (new Socket\BindContext())
                    ->withTlsContext($this->createTlsContext());
                $socket = Socket\listen($bindAddress, $context);
            } else {
                $socket = Socket\listen($bindAddress);
            }

            $this->httpServer = new SocketHttpServer(
                $this->logger,
                new DefaultErrorHandler(),
            );

            $this->httpServer->expose($socket);
            $this->httpServer->start($this, new DefaultErrorHandler());

            // Register with service discovery if configured
            if ($this->serviceDiscovery) {
                foreach ($this->services as $serviceName => $implementation) {
                    $this->serviceDiscovery->register($serviceName, [
                        'host' => $this->config['host'],
                        'port' => $this->config['port'],
                    ])->await();
                }
            }

            $this->logger->info('gRPC server started successfully');
        });
    }

    /**
     * Stop the gRPC server
     */
    public function stop(): Future
    {
        return async(function () {
            $this->logger->info('Stopping gRPC server');

            // Deregister from service discovery
            if ($this->serviceDiscovery) {
                foreach ($this->services as $serviceName => $implementation) {
                    $this->serviceDiscovery->deregister($serviceName)->await();
                }
            }

            if ($this->httpServer) {
                $this->httpServer->stop();
                $this->httpServer = null;
            }

            $this->logger->info('gRPC server stopped');
        });
    }

    /**
     * Handle incoming HTTP/2 request (gRPC uses HTTP/2)
     */
    public function handleRequest(Request $request): Response
    {
        $contentType = $request->getHeader('content-type') ?? '';
        
        // Validate gRPC request
        if (!str_starts_with($contentType, 'application/grpc')) {
            return $this->createErrorResponse(Status::INVALID_ARGUMENT, 'Invalid content-type');
        }

        $path = $request->getUri()->getPath();
        
        // Parse service and method from path: /package.Service/Method
        if (!preg_match('#^/([^/]+)/([^/]+)$#', $path, $matches)) {
            return $this->createErrorResponse(Status::UNIMPLEMENTED, 'Invalid path format');
        }

        $serviceName = $matches[1];
        $methodName = $matches[2];

        // Check if service exists
        if (!$this->hasService($serviceName)) {
            return $this->createErrorResponse(Status::UNIMPLEMENTED, "Service not found: {$serviceName}");
        }

        // Check if method exists
        if (!isset($this->methodDescriptors[$serviceName][$methodName])) {
            return $this->createErrorResponse(Status::UNIMPLEMENTED, "Method not found: {$methodName}");
        }

        $methodDescriptor = $this->methodDescriptors[$serviceName][$methodName];
        $metadata = $this->extractMetadata($request);

        try {
            // Read request body
            $body = $request->getBody()->buffer();
            
            // Validate message size
            if (strlen($body) > $this->config['maxMessageSize']) {
                return $this->createErrorResponse(Status::RESOURCE_EXHAUSTED, 'Message too large');
            }

            // Decode the request
            $decoded = Codec::decodeWithLengthPrefix($body);
            $requestData = $decoded['compressed'] 
                ? Codec::decompress($decoded['data']) 
                : $decoded['data'];

            // Handle based on method type
            $result = match ($methodDescriptor['type']) {
                MethodType::UNARY => $this->handleUnary($serviceName, $methodName, $requestData, $metadata)->await(),
                MethodType::SERVER_STREAMING => $this->handleServerStreamingResponse($serviceName, $methodName, $requestData, $metadata),
                MethodType::CLIENT_STREAMING => $this->handleClientStreamingResponse($serviceName, $methodName, $body, $metadata),
                MethodType::BIDI_STREAMING => $this->handleBidiStreamingResponse($serviceName, $methodName, $body, $metadata),
            };

            if ($result instanceof Response) {
                return $result;
            }

            // Encode response
            $responseData = Codec::encodeWithLengthPrefix($result);

            return new Response(
                status: 200,
                headers: [
                    'content-type' => 'application/grpc+proto',
                    'grpc-status' => '0',
                    'grpc-message' => '',
                ],
                body: $responseData
            );

        } catch (GrpcException $e) {
            return $this->createErrorResponse($e->getStatus(), $e->getMessage(), $e->getMetadata());
        } catch (\Throwable $e) {
            $this->logger->error('gRPC request failed', [
                'service' => $serviceName,
                'method' => $methodName,
                'error' => $e->getMessage(),
            ]);
            return $this->createErrorResponse(Status::INTERNAL, 'Internal server error');
        }
    }

    /**
     * Handle a unary RPC call
     */
    public function handleUnary(
        string $service,
        string $method,
        string $requestData,
        array $metadata = []
    ): Future {
        return async(function () use ($service, $method, $requestData, $metadata) {
            $implementation = $this->services[$service];
            $methodDescriptor = $this->methodDescriptors[$service][$method];

            // Create context
            $context = new Context($metadata);

            // Apply interceptors
            $handler = fn($req, $ctx) => $implementation->$method($req, $ctx);
            
            foreach (array_reverse($this->interceptors) as $interceptor) {
                $next = $handler;
                $handler = fn($req, $ctx) => $interceptor->intercept($req, $ctx, $next);
            }

            // Deserialize request if message class is specified
            $request = $requestData;
            if (isset($methodDescriptor['requestClass'])) {
                $requestClass = $methodDescriptor['requestClass'];
                $request = new $requestClass();
                $request->mergeFromString($requestData);
            }

            // Call the method
            $response = $handler($request, $context);
            
            if ($response instanceof Future) {
                $response = $response->await();
            }

            // Serialize response
            if ($response instanceof Protobuf\MessageInterface) {
                return $response->serializeToString();
            }

            return is_string($response) ? $response : json_encode($response);
        });
    }

    /**
     * Handle a server streaming RPC call
     */
    public function handleServerStreaming(
        string $service,
        string $method,
        string $requestData,
        array $metadata = []
    ): Future {
        return async(function () use ($service, $method, $requestData, $metadata) {
            $implementation = $this->services[$service];
            $methodDescriptor = $this->methodDescriptors[$service][$method];
            $context = new Context($metadata);

            // Deserialize request
            $request = $requestData;
            if (isset($methodDescriptor['requestClass'])) {
                $requestClass = $methodDescriptor['requestClass'];
                $request = new $requestClass();
                $request->mergeFromString($requestData);
            }

            // Call the streaming method
            $stream = $implementation->$method($request, $context);
            
            $responses = [];
            foreach ($stream as $response) {
                if ($response instanceof Protobuf\MessageInterface) {
                    $responses[] = $response->serializeToString();
                } else {
                    $responses[] = is_string($response) ? $response : json_encode($response);
                }
            }

            return $responses;
        });
    }

    /**
     * Handle a client streaming RPC call
     */
    public function handleClientStreaming(
        string $service,
        string $method,
        iterable $requestStream,
        array $metadata = []
    ): Future {
        return async(function () use ($service, $method, $requestStream, $metadata) {
            $implementation = $this->services[$service];
            $methodDescriptor = $this->methodDescriptors[$service][$method];
            $context = new Context($metadata);

            // Create request iterator
            $requests = [];
            foreach ($requestStream as $requestData) {
                if (isset($methodDescriptor['requestClass'])) {
                    $requestClass = $methodDescriptor['requestClass'];
                    $request = new $requestClass();
                    $request->mergeFromString($requestData);
                    $requests[] = $request;
                } else {
                    $requests[] = $requestData;
                }
            }

            // Call the method with all requests
            $response = $implementation->$method($requests, $context);
            
            if ($response instanceof Future) {
                $response = $response->await();
            }

            if ($response instanceof Protobuf\MessageInterface) {
                return $response->serializeToString();
            }

            return is_string($response) ? $response : json_encode($response);
        });
    }

    /**
     * Handle a bidirectional streaming RPC call
     */
    public function handleBidiStreaming(
        string $service,
        string $method,
        iterable $requestStream,
        array $metadata = []
    ): Future {
        return async(function () use ($service, $method, $requestStream, $metadata) {
            $implementation = $this->services[$service];
            $methodDescriptor = $this->methodDescriptors[$service][$method];
            $context = new Context($metadata);

            // Create async generator for requests
            $requestGenerator = function () use ($requestStream, $methodDescriptor) {
                foreach ($requestStream as $requestData) {
                    if (isset($methodDescriptor['requestClass'])) {
                        $requestClass = $methodDescriptor['requestClass'];
                        $request = new $requestClass();
                        $request->mergeFromString($requestData);
                        yield $request;
                    } else {
                        yield $requestData;
                    }
                }
            };

            // Call the bidirectional streaming method
            $responseStream = $implementation->$method($requestGenerator(), $context);
            
            $responses = [];
            foreach ($responseStream as $response) {
                if ($response instanceof Protobuf\MessageInterface) {
                    $responses[] = $response->serializeToString();
                } else {
                    $responses[] = is_string($response) ? $response : json_encode($response);
                }
            }

            return $responses;
        });
    }

    /**
     * Register a service implementation
     */
    public function registerService(string $serviceName, object $implementation): void
    {
        $this->services[$serviceName] = $implementation;
        
        // Extract method descriptors
        if ($implementation instanceof ServiceInterface) {
            $this->methodDescriptors[$serviceName] = $implementation->getMethods();
        } else {
            // Auto-discover methods using reflection
            $this->methodDescriptors[$serviceName] = $this->discoverMethods($implementation);
        }

        $this->logger->info('Registered gRPC service', ['service' => $serviceName]);
    }

    /**
     * Check if a service is registered
     */
    public function hasService(string $serviceName): bool
    {
        return isset($this->services[$serviceName]);
    }

    /**
     * Get all registered services
     */
    public function getServices(): array
    {
        return $this->services;
    }

    /**
     * Add an interceptor
     */
    public function addInterceptor(InterceptorInterface $interceptor): void
    {
        $this->interceptors[] = $interceptor;
    }

    /**
     * Set service discovery
     */
    public function setServiceDiscovery(ServiceDiscoveryInterface $discovery): void
    {
        $this->serviceDiscovery = $discovery;
    }

    /**
     * Set load balancer
     */
    public function setLoadBalancer(LoadBalancerInterface $loadBalancer): void
    {
        $this->loadBalancer = $loadBalancer;
    }

    /**
     * Handle server streaming response
     */
    protected function handleServerStreamingResponse(
        string $service,
        string $method,
        string $requestData,
        array $metadata
    ): Response {
        $responses = $this->handleServerStreaming($service, $method, $requestData, $metadata)->await();
        
        $body = '';
        foreach ($responses as $response) {
            $body .= Codec::encodeWithLengthPrefix($response);
        }

        return new Response(
            status: 200,
            headers: [
                'content-type' => 'application/grpc+proto',
                'grpc-status' => '0',
            ],
            body: $body
        );
    }

    /**
     * Handle client streaming response
     */
    protected function handleClientStreamingResponse(
        string $service,
        string $method,
        string $body,
        array $metadata
    ): Response {
        // Decode all messages from the body
        $messages = Codec::decodeMultiple($body);
        $requestStream = array_map(fn($m) => $m['data'], $messages);

        $response = $this->handleClientStreaming($service, $method, $requestStream, $metadata)->await();
        $responseData = Codec::encodeWithLengthPrefix($response);

        return new Response(
            status: 200,
            headers: [
                'content-type' => 'application/grpc+proto',
                'grpc-status' => '0',
            ],
            body: $responseData
        );
    }

    /**
     * Handle bidirectional streaming response
     */
    protected function handleBidiStreamingResponse(
        string $service,
        string $method,
        string $body,
        array $metadata
    ): Response {
        $messages = Codec::decodeMultiple($body);
        $requestStream = array_map(fn($m) => $m['data'], $messages);

        $responses = $this->handleBidiStreaming($service, $method, $requestStream, $metadata)->await();
        
        $responseBody = '';
        foreach ($responses as $response) {
            $responseBody .= Codec::encodeWithLengthPrefix($response);
        }

        return new Response(
            status: 200,
            headers: [
                'content-type' => 'application/grpc+proto',
                'grpc-status' => '0',
            ],
            body: $responseBody
        );
    }

    /**
     * Extract metadata from request headers
     */
    protected function extractMetadata(Request $request): array
    {
        $metadata = [];
        
        foreach ($request->getHeaders() as $name => $values) {
            // gRPC metadata headers
            if (str_starts_with($name, 'grpc-') || $name === 'authorization') {
                $metadata[$name] = $values[0] ?? '';
            }
        }

        return $metadata;
    }

    /**
     * Create an error response
     */
    protected function createErrorResponse(Status $status, string $message, array $metadata = []): Response
    {
        $headers = [
            'content-type' => 'application/grpc+proto',
            'grpc-status' => (string)$status->value,
            'grpc-message' => rawurlencode($message),
        ];

        foreach ($metadata as $key => $value) {
            $headers["grpc-{$key}"] = $value;
        }

        return new Response(
            status: 200, // gRPC always returns 200, status is in headers
            headers: $headers,
            body: ''
        );
    }

    /**
     * Create TLS context for secure connections
     */
    protected function createTlsContext(): Socket\ServerTlsContext
    {
        $tls = $this->config['tls'];
        
        return (new Socket\ServerTlsContext())
            ->withDefaultCertificate(new Socket\Certificate($tls['cert'], $tls['key']))
            ->withMinimumVersion(Socket\TlsInfo::TLS_1_2);
    }

    /**
     * Auto-discover methods from implementation
     */
    protected function discoverMethods(object $implementation): array
    {
        $methods = [];
        $reflection = new \ReflectionClass($implementation);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isDestructor()) {
                continue;
            }

            $name = $method->getName();
            
            // Determine method type from attributes or naming convention
            $type = MethodType::UNARY;
            
            $attributes = $method->getAttributes(GrpcMethod::class);
            if (!empty($attributes)) {
                $attr = $attributes[0]->newInstance();
                $type = $attr->type;
            } elseif (str_contains($name, 'Stream')) {
                $type = MethodType::SERVER_STREAMING;
            }

            $methods[$name] = [
                'type' => $type,
                'request' => 'bytes',
                'response' => 'bytes',
            ];
        }

        return $methods;
    }
}
