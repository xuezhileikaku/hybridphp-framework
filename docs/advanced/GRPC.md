# gRPC 微服务通信

HybridPHP 提供完整的 gRPC 支持，用于构建高性能的微服务通信系统。

## 核心特性

- **异步 gRPC**: 基于 AMPHP 的非阻塞 gRPC 实现
- **多种调用模式**: Unary、Server Streaming、Client Streaming、Bidirectional
- **服务发现**: 支持 Consul、内存等服务发现机制
- **负载均衡**: Round Robin、加权、一致性哈希等策略
- **拦截器**: 日志、认证、指标收集等中间件支持

## 快速开始

### 基础配置

```php
// config/grpc.php
return [
    'server' => [
        'host' => '0.0.0.0',
        'port' => 50051,
        'workers' => 4,
    ],
    
    'client' => [
        'timeout' => 30,
        'retry' => [
            'max_attempts' => 3,
            'initial_backoff' => 0.1,
        ],
    ],
    
    'discovery' => [
        'type' => 'consul',
        'host' => 'localhost',
        'port' => 8500,
    ],
    
    'load_balancer' => 'round_robin',
];
```

## 定义服务

### 消息定义

```php
use HybridPHP\Core\Grpc\Protobuf\AbstractMessage;

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
```

### 服务实现

```php
use HybridPHP\Core\Grpc\ServiceInterface;
use HybridPHP\Core\Grpc\Context;
use HybridPHP\Core\Grpc\MethodType;
use HybridPHP\Core\Grpc\GrpcMethod;

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

    // Unary RPC
    #[GrpcMethod(MethodType::UNARY, HelloRequest::class, HelloResponse::class)]
    public function SayHello(HelloRequest $request, Context $context): HelloResponse
    {
        $response = new HelloResponse();
        $response->setMessage("Hello, {$request->getName()}!");
        return $response;
    }

    // Server Streaming RPC
    #[GrpcMethod(MethodType::SERVER_STREAMING, HelloRequest::class, HelloResponse::class)]
    public function SayHelloStream(HelloRequest $request, Context $context): \Generator
    {
        $name = $request->getName();
        
        for ($i = 1; $i <= 5; $i++) {
            $response = new HelloResponse();
            $response->setMessage("Hello #{$i}, {$name}!");
            yield $response;
            
            \Amp\delay(0.1);
        }
    }
}
```

## gRPC 服务器

### 启动服务器

```php
use HybridPHP\Core\Grpc\GrpcServer;
use HybridPHP\Core\Grpc\Interceptors\LoggingInterceptor;
use HybridPHP\Core\Grpc\Interceptors\MetricsInterceptor;

$server = new GrpcServer([
    'host' => '0.0.0.0',
    'port' => 50051,
]);

// 注册服务
$server->registerService('example.Greeter', new GreeterService());

// 添加拦截器
$server->addInterceptor(new LoggingInterceptor());
$server->addInterceptor(new MetricsInterceptor());

// 启动服务器
$server->start()->await();

echo "gRPC server listening on 0.0.0.0:50051\n";
```

### 服务注册

```php
// 注册多个服务
$server->registerService('example.Greeter', new GreeterService());
$server->registerService('example.UserService', new UserService());
$server->registerService('example.OrderService', new OrderService());
```

## gRPC 客户端

### 基础调用

```php
use HybridPHP\Core\Grpc\GrpcClient;

$client = new GrpcClient([
    'host' => 'localhost',
    'port' => 50051,
]);

// 创建请求
$request = new HelloRequest();
$request->setName('World');

// Unary 调用
$response = $client->unary(
    'example.Greeter',
    'SayHello',
    $request
)->await();

echo "Response: {$response->getMessage()}\n";
```

### Server Streaming 调用

```php
// 服务端流式调用
$responses = $client->serverStreaming(
    'example.Greeter',
    'SayHelloStream',
    $request
)->await();

foreach ($responses as $response) {
    echo "Stream response: {$response->getMessage()}\n";
}
```

### Client Streaming 调用

```php
// 客户端流式调用
$requests = function () {
    for ($i = 1; $i <= 5; $i++) {
        $request = new HelloRequest();
        $request->setName("User {$i}");
        yield $request;
    }
};

$response = $client->clientStreaming(
    'example.Greeter',
    'SayHelloClientStream',
    $requests()
)->await();
```

### Bidirectional Streaming 调用

```php
// 双向流式调用
$stream = $client->bidirectionalStreaming(
    'example.Chat',
    'Chat'
)->await();

// 发送消息
$stream->send($message1);
$stream->send($message2);

// 接收消息
foreach ($stream->receive() as $response) {
    echo "Received: {$response->getMessage()}\n";
}
```

## 服务发现

### Consul 服务发现

```php
use HybridPHP\Core\Grpc\Discovery\ConsulServiceDiscovery;

$discovery = new ConsulServiceDiscovery([
    'host' => 'localhost',
    'port' => 8500,
]);

// 注册服务实例
$discovery->register('example.Greeter', [
    'id' => 'greeter-1',
    'host' => '192.168.1.10',
    'port' => 50051,
    'weight' => 100,
    'tags' => ['production'],
])->await();

// 发现服务实例
$instances = $discovery->discover('example.Greeter')->await();

foreach ($instances as $instance) {
    echo "Found: {$instance->getAddress()}\n";
}

// 注销服务
$discovery->deregister('example.Greeter', 'greeter-1')->await();
```

### 内存服务发现

```php
use HybridPHP\Core\Grpc\Discovery\InMemoryServiceDiscovery;

$discovery = new InMemoryServiceDiscovery();

// 注册多个实例
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
```

## 负载均衡

### Round Robin

```php
use HybridPHP\Core\Grpc\LoadBalancer\RoundRobinLoadBalancer;

$client = new GrpcClient();
$client->setServiceDiscovery($discovery);
$client->setLoadBalancer(new RoundRobinLoadBalancer());
```

### 加权负载均衡

```php
use HybridPHP\Core\Grpc\LoadBalancer\WeightedLoadBalancer;

$client->setLoadBalancer(new WeightedLoadBalancer());
```

### 最少连接

```php
use HybridPHP\Core\Grpc\LoadBalancer\LeastConnectionsLoadBalancer;

$client->setLoadBalancer(new LeastConnectionsLoadBalancer());
```

### 一致性哈希

```php
use HybridPHP\Core\Grpc\LoadBalancer\ConsistentHashLoadBalancer;

$client->setLoadBalancer(new ConsistentHashLoadBalancer());
```

## 拦截器

### 日志拦截器

```php
use HybridPHP\Core\Grpc\Interceptors\LoggingInterceptor;

$server->addInterceptor(new LoggingInterceptor($logger));
```

### 认证拦截器

```php
use HybridPHP\Core\Grpc\Interceptors\AuthInterceptor;

$server->addInterceptor(new AuthInterceptor([
    'secret' => 'your-jwt-secret',
    'excluded_methods' => ['Health.Check'],
]));
```

### 指标拦截器

```php
use HybridPHP\Core\Grpc\Interceptors\MetricsInterceptor;

$server->addInterceptor(new MetricsInterceptor($metricsCollector));
```

### 重试拦截器

```php
use HybridPHP\Core\Grpc\Interceptors\RetryInterceptor;

$client->addInterceptor(new RetryInterceptor([
    'max_attempts' => 3,
    'initial_backoff' => 0.1,
    'max_backoff' => 1.0,
    'backoff_multiplier' => 2.0,
]));
```

### 自定义拦截器

```php
use HybridPHP\Core\Grpc\InterceptorInterface;
use HybridPHP\Core\Grpc\Context;

class CustomInterceptor implements InterceptorInterface
{
    public function intercept(mixed $request, Context $context, callable $next): mixed
    {
        // 前置处理
        $context->setMetadata('x-custom-header', 'custom-value');
        $startTime = microtime(true);
        
        // 调用下一个拦截器或实际方法
        $response = $next($request, $context);
        
        // 后置处理
        $duration = microtime(true) - $startTime;
        echo "Request took {$duration}s\n";
        
        return $response;
    }
}
```

## Context 上下文

### 元数据传递

```php
// 服务端获取元数据
public function SayHello(HelloRequest $request, Context $context): HelloResponse
{
    $userId = $context->getMetadata('x-user-id');
    $traceId = $context->getMetadata('x-trace-id');
    
    // 处理请求...
}

// 客户端设置元数据
$context = new Context();
$context->setMetadata('x-user-id', '12345');
$context->setMetadata('x-trace-id', 'abc-123');

$response = $client->unary(
    'example.Greeter',
    'SayHello',
    $request,
    $context
)->await();
```

### 超时控制

```php
$context = new Context();
$context->setTimeout(5.0); // 5秒超时

$response = $client->unary(
    'example.Greeter',
    'SayHello',
    $request,
    $context
)->await();
```

### 取消请求

```php
$context = new Context();

// 在另一个协程中取消
async(function () use ($context) {
    \Amp\delay(2.0);
    $context->cancel();
});

try {
    $response = $client->unary(
        'example.Greeter',
        'SayHello',
        $request,
        $context
    )->await();
} catch (GrpcException $e) {
    if ($e->getCode() === Status::CANCELLED) {
        echo "Request was cancelled\n";
    }
}
```

## 错误处理

### gRPC 状态码

```php
use HybridPHP\Core\Grpc\Status;
use HybridPHP\Core\Grpc\GrpcException;

try {
    $response = $client->unary('service', 'method', $request)->await();
} catch (GrpcException $e) {
    switch ($e->getCode()) {
        case Status::NOT_FOUND:
            echo "Resource not found\n";
            break;
        case Status::PERMISSION_DENIED:
            echo "Permission denied\n";
            break;
        case Status::UNAVAILABLE:
            echo "Service unavailable\n";
            break;
        case Status::DEADLINE_EXCEEDED:
            echo "Request timeout\n";
            break;
        default:
            echo "Error: {$e->getMessage()}\n";
    }
}
```

### 服务端返回错误

```php
public function GetUser(GetUserRequest $request, Context $context): GetUserResponse
{
    $user = $this->userRepository->find($request->getId());
    
    if (!$user) {
        throw new GrpcException(
            'User not found',
            Status::NOT_FOUND
        );
    }
    
    return $this->toResponse($user);
}
```

## 服务提供者

```php
use HybridPHP\Core\Grpc\GrpcServiceProvider;

$container = new Container();
$config = require 'config/grpc.php';

$provider = new GrpcServiceProvider($container, $config);
$provider->register();

// 获取服务器
$server = $container->get(GrpcServer::class);
$server->registerService('example.Greeter', new GreeterService());

// 获取客户端
$client = $container->get(GrpcClient::class);
```

## 健康检查

### 实现健康检查服务

```php
class HealthService implements ServiceInterface
{
    public function getServiceName(): string
    {
        return 'grpc.health.v1.Health';
    }

    public function getMethods(): array
    {
        return [
            'Check' => [
                'type' => MethodType::UNARY,
                'requestClass' => HealthCheckRequest::class,
                'responseClass' => HealthCheckResponse::class,
            ],
        ];
    }

    public function Check(HealthCheckRequest $request, Context $context): HealthCheckResponse
    {
        $response = new HealthCheckResponse();
        $response->setStatus(HealthCheckResponse::SERVING);
        return $response;
    }
}

$server->registerService('grpc.health.v1.Health', new HealthService());
```

## 最佳实践

### 1. 服务设计

```php
// ✅ 好的设计：细粒度服务
class UserService { }
class OrderService { }
class PaymentService { }

// ❌ 避免：单一大服务
class EverythingService { }
```

### 2. 错误处理

```php
// ✅ 使用合适的状态码
throw new GrpcException('User not found', Status::NOT_FOUND);
throw new GrpcException('Invalid input', Status::INVALID_ARGUMENT);

// ❌ 避免：总是返回 UNKNOWN
throw new GrpcException('Error', Status::UNKNOWN);
```

### 3. 超时设置

```php
// ✅ 设置合理的超时
$context->setTimeout(5.0);

// ❌ 避免：无限等待
// 不设置超时
```

### 4. 重试策略

```php
// ✅ 只对幂等操作重试
$retryInterceptor = new RetryInterceptor([
    'retryable_methods' => ['Get*', 'List*'],
    'max_attempts' => 3,
]);
```

## 下一步

- [微服务架构](../applications/MICROSERVICES.md) - 基于 gRPC 的微服务设计
- [分布式追踪](./TRACING.md) - gRPC 调用追踪
- [IM 即时通讯系统](../applications/IM_SYSTEM.md) - gRPC 在 IM 中的应用
