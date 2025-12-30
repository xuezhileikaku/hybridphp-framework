<?php

/**
 * gRPC Usage Examples
 * 
 * This file demonstrates how to use the HybridPHP gRPC module
 * for building async gRPC servers and clients.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HybridPHP\Core\Container;
use HybridPHP\Core\Grpc\GrpcServer;
use HybridPHP\Core\Grpc\GrpcClient;
use HybridPHP\Core\Grpc\GrpcServiceProvider;
use HybridPHP\Core\Grpc\ServiceInterface;
use HybridPHP\Core\Grpc\Context;
use HybridPHP\Core\Grpc\MethodType;
use HybridPHP\Core\Grpc\GrpcMethod;
use HybridPHP\Core\Grpc\Protobuf\AbstractMessage;
use HybridPHP\Core\Grpc\Discovery\InMemoryServiceDiscovery;
use HybridPHP\Core\Grpc\LoadBalancer\RoundRobinLoadBalancer;
use HybridPHP\Core\Grpc\Interceptors\LoggingInterceptor;
use HybridPHP\Core\Grpc\Interceptors\MetricsInterceptor;
use function Amp\async;

// ============================================================================
// Example 1: Define a simple gRPC service
// ============================================================================

/**
 * Example request message
 */
class HelloRequest extends AbstractMessage
{
    public static function getDescriptor(): string
    {
        return 'example.HelloRequest';
    }

    protected function getFieldDescriptors(): array
    {
        return [
            1 => ['name' => 'name', 'type' => 'string'],
        ];
    }

    public function getName(): string
    {
        return $this->data['name'] ?? '';
    }

    public function setName(string $name): self
    {
        $this->data['name'] = $name;
        return $this;
    }
}

/**
 * Example response message
 */
class HelloResponse extends AbstractMessage
{
    public static function getDescriptor(): string
    {
        return 'example.HelloResponse';
    }

    protected function getFieldDescriptors(): array
    {
        return [
            1 => ['name' => 'message', 'type' => 'string'],
        ];
    }

    public function getMessage(): string
    {
        return $this->data['message'] ?? '';
    }

    public function setMessage(string $message): self
    {
        $this->data['message'] = $message;
        return $this;
    }
}

/**
 * Example gRPC service implementation
 */
class GreeterService implements ServiceInterface
{
    public function getServiceName(): string
    {
        return 'example.Greeter';
    }

    public function getMethods(): array
    {
        return [
            'SayHello' => [
                'type' => MethodType::UNARY,
                'requestClass' => HelloRequest::class,
                'responseClass' => HelloResponse::class,
            ],
            'SayHelloStream' => [
                'type' => MethodType::SERVER_STREAMING,
                'requestClass' => HelloRequest::class,
                'responseClass' => HelloResponse::class,
            ],
        ];
    }

    /**
     * Unary RPC: Say hello
     */
    #[GrpcMethod(MethodType::UNARY, HelloRequest::class, HelloResponse::class)]
    public function SayHello(HelloRequest $request, Context $context): HelloResponse
    {
        $response = new HelloResponse();
        $response->setMessage("Hello, {$request->getName()}!");
        return $response;
    }

    /**
     * Server streaming RPC: Say hello multiple times
     */
    #[GrpcMethod(MethodType::SERVER_STREAMING, HelloRequest::class, HelloResponse::class)]
    public function SayHelloStream(HelloRequest $request, Context $context): \Generator
    {
        $name = $request->getName();
        
        for ($i = 1; $i <= 5; $i++) {
            $response = new HelloResponse();
            $response->setMessage("Hello #{$i}, {$name}!");
            yield $response;
            
            // Simulate some processing time
            \Amp\delay(0.1);
        }
    }
}

// ============================================================================
// Example 2: Start a gRPC server
// ============================================================================

function startServer(): void
{
    echo "Starting gRPC server example...\n";

    $server = new GrpcServer([
        'host' => '0.0.0.0',
        'port' => 50051,
    ]);

    // Register service
    $server->registerService('example.Greeter', new GreeterService());

    // Add interceptors
    $server->addInterceptor(new LoggingInterceptor());
    $server->addInterceptor(new MetricsInterceptor());

    // Start server
    $server->start()->await();

    echo "gRPC server listening on 0.0.0.0:50051\n";
}

// ============================================================================
// Example 3: Use gRPC client
// ============================================================================

function clientExample(): void
{
    echo "gRPC client example...\n";

    $client = new GrpcClient([
        'host' => 'localhost',
        'port' => 50051,
    ]);

    // Create request
    $request = new HelloRequest();
    $request->setName('World');

    // Make unary call
    $response = $client->unary(
        'example.Greeter',
        'SayHello',
        $request
    )->await();

    echo "Response: {$response}\n";

    // Make server streaming call
    $responses = $client->serverStreaming(
        'example.Greeter',
        'SayHelloStream',
        $request
    )->await();

    foreach ($responses as $response) {
        echo "Stream response: {$response['data']}\n";
    }
}

// ============================================================================
// Example 4: Service discovery and load balancing
// ============================================================================

function serviceDiscoveryExample(): void
{
    echo "Service discovery example...\n";

    // Create service discovery
    $discovery = new InMemoryServiceDiscovery();

    // Register service instances
    $discovery->register('example.Greeter', [
        'id' => 'instance-1',
        'host' => 'localhost',
        'port' => 50051,
        'weight' => 100,
    ])->await();

    $discovery->register('example.Greeter', [
        'id' => 'instance-2',
        'host' => 'localhost',
        'port' => 50052,
        'weight' => 50,
    ])->await();

    // Create client with service discovery
    $client = new GrpcClient();
    $client->setServiceDiscovery($discovery);
    $client->setLoadBalancer(new RoundRobinLoadBalancer());

    // Discover instances
    $instances = $discovery->discover('example.Greeter')->await();
    echo "Found " . count($instances) . " instances\n";

    foreach ($instances as $instance) {
        echo "  - {$instance->id}: {$instance->getAddress()}\n";
    }
}

// ============================================================================
// Example 5: Using the service provider
// ============================================================================

function serviceProviderExample(): void
{
    echo "Service provider example...\n";

    $container = new Container();

    // Load configuration
    $config = require __DIR__ . '/../config/grpc.php';

    // Register gRPC services
    $provider = new GrpcServiceProvider($container, $config);
    $provider->register();

    // Get server from container
    $server = $container->get(GrpcServer::class);
    $server->registerService('example.Greeter', new GreeterService());

    // Get client from container
    $client = $container->get(GrpcClient::class);

    echo "gRPC services registered successfully\n";
}

// ============================================================================
// Example 6: Custom interceptor
// ============================================================================

use HybridPHP\Core\Grpc\InterceptorInterface;

class CustomInterceptor implements InterceptorInterface
{
    public function intercept(mixed $request, Context $context, callable $next): mixed
    {
        // Before call
        echo "Before gRPC call\n";
        $context->setMetadata('x-custom-header', 'custom-value');

        // Make the call
        $response = $next($request, $context);

        // After call
        echo "After gRPC call\n";

        return $response;
    }
}

// ============================================================================
// Run examples
// ============================================================================

if (php_sapi_name() === 'cli') {
    $command = $argv[1] ?? 'help';

    switch ($command) {
        case 'server':
            startServer();
            // Keep running
            while (true) {
                \Amp\delay(1);
            }
            break;

        case 'client':
            clientExample();
            break;

        case 'discovery':
            serviceDiscoveryExample();
            break;

        case 'provider':
            serviceProviderExample();
            break;

        default:
            echo "HybridPHP gRPC Examples\n";
            echo "Usage: php grpc_usage.php <command>\n\n";
            echo "Commands:\n";
            echo "  server    - Start a gRPC server\n";
            echo "  client    - Run client examples\n";
            echo "  discovery - Service discovery example\n";
            echo "  provider  - Service provider example\n";
            break;
    }
}
