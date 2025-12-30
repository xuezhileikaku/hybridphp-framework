# 微服务架构实战

本文档介绍如何使用 HybridPHP 构建微服务架构，涵盖服务拆分、通信、治理和部署等核心内容。

## 架构概述

### 微服务架构图

```
┌─────────────────────────────────────────────────────────────────┐
│                        API Gateway                              │
│                    (HybridPHP HTTP/2)                          │
└───────────────────────────┬─────────────────────────────────────┘
                            │
        ┌───────────────────┼───────────────────┐
        │                   │                   │
        ▼                   ▼                   ▼
┌───────────────┐   ┌───────────────┐   ┌───────────────┐
│  User Service │   │ Order Service │   │Product Service│
│    (gRPC)     │   │    (gRPC)     │   │    (gRPC)     │
└───────┬───────┘   └───────┬───────┘   └───────┬───────┘
        │                   │                   │
        ▼                   ▼                   ▼
┌───────────────┐   ┌───────────────┐   ┌───────────────┐
│    MySQL      │   │    MySQL      │   │   MongoDB     │
└───────────────┘   └───────────────┘   └───────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                    Infrastructure                               │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐           │
│  │  Redis  │  │  Consul │  │  Jaeger │  │Prometheus│           │
│  └─────────┘  └─────────┘  └─────────┘  └─────────┘           │
└─────────────────────────────────────────────────────────────────┘
```

### 核心组件

| 组件 | 技术 | 职责 |
|------|------|------|
| API Gateway | HybridPHP HTTP/2 | 统一入口、路由、认证 |
| 服务通信 | gRPC | 高性能 RPC 调用 |
| 服务发现 | Consul | 服务注册与发现 |
| 配置中心 | Consul KV | 配置管理 |
| 链路追踪 | Jaeger | 分布式追踪 |
| 监控 | Prometheus + Grafana | 指标监控 |

## API Gateway 实现

### 网关配置

```php
<?php
// app/Gateway/ApiGateway.php

namespace App\Gateway;

use HybridPHP\Core\Server\Http2Server;
use HybridPHP\Core\Routing\Router;
use HybridPHP\Core\Container;
use HybridPHP\Core\Grpc\GrpcClient;

class ApiGateway
{
    private Http2Server $server;
    private Router $router;
    private ServiceRegistry $registry;
    private array $clients = [];
    
    public function __construct(Container $container, array $config)
    {
        $this->router = new Router();
        $this->registry = $container->get(ServiceRegistry::class);
        
        $this->setupRoutes();
        $this->setupMiddleware();
        
        $this->server = new Http2Server($this->router, $container, $config);
    }
    
    private function setupRoutes(): void
    {
        // 用户服务路由
        $this->router->group(['prefix' => '/api/v1/users'], function () {
            $this->router->get('/', [$this, 'listUsers']);
            $this->router->get('/{id}', [$this, 'getUser']);
            $this->router->post('/', [$this, 'createUser']);
        });
        
        // 订单服务路由
        $this->router->group(['prefix' => '/api/v1/orders'], function () {
            $this->router->get('/', [$this, 'listOrders']);
            $this->router->get('/{id}', [$this, 'getOrder']);
            $this->router->post('/', [$this, 'createOrder']);
        });
        
        // 商品服务路由
        $this->router->group(['prefix' => '/api/v1/products'], function () {
            $this->router->get('/', [$this, 'listProducts']);
            $this->router->get('/{id}', [$this, 'getProduct']);
        });
    }
    
    private function setupMiddleware(): void
    {
        // 认证中间件
        $this->server->addMiddleware(new AuthMiddleware());
        
        // 限流中间件
        $this->server->addMiddleware(new RateLimitMiddleware([
            'max_requests' => 1000,
            'window_seconds' => 60,
        ]));
        
        // 追踪中间件
        $this->server->addMiddleware(new TracingMiddleware($this->tracer));
        
        // 日志中间件
        $this->server->addMiddleware(new LoggingMiddleware($this->logger));
    }
    
    public function getUser(Request $request, array $params): Response
    {
        $client = $this->getServiceClient('user-service');
        
        $grpcRequest = new GetUserRequest();
        $grpcRequest->setId($params['id']);
        
        $response = $client->unary(
            'user.UserService',
            'GetUser',
            $grpcRequest
        )->await();
        
        return ResponseFactory::json($response->toArray());
    }
    
    public function createOrder(Request $request): Response
    {
        $data = $request->getParsedBody();
        
        // 调用订单服务
        $client = $this->getServiceClient('order-service');
        
        $grpcRequest = new CreateOrderRequest();
        $grpcRequest->setUserId($data['user_id']);
        $grpcRequest->setItems($data['items']);
        
        $response = $client->unary(
            'order.OrderService',
            'CreateOrder',
            $grpcRequest
        )->await();
        
        return ResponseFactory::json($response->toArray(), 201);
    }
    
    private function getServiceClient(string $serviceName): GrpcClient
    {
        if (!isset($this->clients[$serviceName])) {
            $instances = $this->registry->discover($serviceName);
            
            $client = new GrpcClient();
            $client->setServiceDiscovery($this->registry);
            $client->setLoadBalancer(new RoundRobinLoadBalancer());
            
            $this->clients[$serviceName] = $client;
        }
        
        return $this->clients[$serviceName];
    }
}
```

## 服务实现

### 用户服务

```php
<?php
// services/user-service/UserService.php

namespace UserService;

use HybridPHP\Core\Grpc\ServiceInterface;
use HybridPHP\Core\Grpc\Context;
use HybridPHP\Core\Grpc\MethodType;

class UserService implements ServiceInterface
{
    private UserRepository $repository;
    private Tracer $tracer;
    
    public function getServiceName(): string
    {
        return 'user.UserService';
    }
    
    public function getMethods(): array
    {
        return [
            'GetUser' => [
                'type' => MethodType::UNARY,
                'requestClass' => GetUserRequest::class,
                'responseClass' => GetUserResponse::class,
            ],
            'CreateUser' => [
                'type' => MethodType::UNARY,
                'requestClass' => CreateUserRequest::class,
                'responseClass' => CreateUserResponse::class,
            ],
            'ListUsers' => [
                'type' => MethodType::UNARY,
                'requestClass' => ListUsersRequest::class,
                'responseClass' => ListUsersResponse::class,
            ],
        ];
    }
    
    public function GetUser(GetUserRequest $request, Context $context): GetUserResponse
    {
        $span = $this->tracer->startSpan('user.get', [
            'user_id' => $request->getId(),
        ]);
        
        try {
            $user = $this->repository->find($request->getId());
            
            if (!$user) {
                throw new GrpcException('User not found', Status::NOT_FOUND);
            }
            
            $response = new GetUserResponse();
            $response->setUser($this->toProtoUser($user));
            
            $span->setStatus(SpanStatus::OK);
            return $response;
            
        } catch (\Throwable $e) {
            $span->recordException($e);
            throw $e;
        } finally {
            $span->end();
        }
    }
    
    public function CreateUser(CreateUserRequest $request, Context $context): CreateUserResponse
    {
        $span = $this->tracer->startSpan('user.create');
        
        try {
            // 验证数据
            $this->validateUserData($request);
            
            // 创建用户
            $user = $this->repository->create([
                'name' => $request->getName(),
                'email' => $request->getEmail(),
                'password' => password_hash($request->getPassword(), PASSWORD_DEFAULT),
            ]);
            
            // 发布事件
            $this->eventBus->publish('user.created', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
            
            $response = new CreateUserResponse();
            $response->setUser($this->toProtoUser($user));
            
            $span->setStatus(SpanStatus::OK);
            return $response;
            
        } catch (\Throwable $e) {
            $span->recordException($e);
            throw $e;
        } finally {
            $span->end();
        }
    }
}
```

### 订单服务

```php
<?php
// services/order-service/OrderService.php

namespace OrderService;

use HybridPHP\Core\Grpc\ServiceInterface;
use HybridPHP\Core\Grpc\Context;
use HybridPHP\Core\Grpc\GrpcClient;

class OrderService implements ServiceInterface
{
    private OrderRepository $repository;
    private GrpcClient $userClient;
    private GrpcClient $productClient;
    private Tracer $tracer;
    
    public function getServiceName(): string
    {
        return 'order.OrderService';
    }
    
    public function CreateOrder(CreateOrderRequest $request, Context $context): CreateOrderResponse
    {
        $span = $this->tracer->startSpan('order.create', [
            'user_id' => $request->getUserId(),
            'items_count' => count($request->getItems()),
        ]);
        
        try {
            // 验证用户
            $this->validateUser($request->getUserId(), $context);
            
            // 验证商品库存
            $this->validateProducts($request->getItems(), $context);
            
            // 计算总价
            $totalAmount = $this->calculateTotal($request->getItems());
            
            // 创建订单
            $order = $this->repository->create([
                'user_id' => $request->getUserId(),
                'items' => $request->getItems(),
                'total_amount' => $totalAmount,
                'status' => 'pending',
            ]);
            
            // 扣减库存
            $this->deductInventory($request->getItems(), $context);
            
            // 发布订单创建事件
            $this->eventBus->publish('order.created', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'total_amount' => $totalAmount,
            ]);
            
            $response = new CreateOrderResponse();
            $response->setOrder($this->toProtoOrder($order));
            
            $span->setStatus(SpanStatus::OK);
            return $response;
            
        } catch (\Throwable $e) {
            $span->recordException($e);
            throw $e;
        } finally {
            $span->end();
        }
    }
    
    private function validateUser(int $userId, Context $context): void
    {
        $span = $this->tracer->startSpan('order.validate_user');
        
        // 传播追踪上下文
        $headers = [];
        $this->tracer->inject($headers);
        $context->setMetadata('traceparent', $headers['traceparent'] ?? '');
        
        $request = new GetUserRequest();
        $request->setId($userId);
        
        try {
            $response = $this->userClient->unary(
                'user.UserService',
                'GetUser',
                $request,
                $context
            )->await();
            
            $span->setStatus(SpanStatus::OK);
        } catch (GrpcException $e) {
            if ($e->getCode() === Status::NOT_FOUND) {
                throw new GrpcException('User not found', Status::INVALID_ARGUMENT);
            }
            throw $e;
        } finally {
            $span->end();
        }
    }
    
    private function validateProducts(array $items, Context $context): void
    {
        $span = $this->tracer->startSpan('order.validate_products');
        
        foreach ($items as $item) {
            $request = new CheckInventoryRequest();
            $request->setProductId($item['product_id']);
            $request->setQuantity($item['quantity']);
            
            $response = $this->productClient->unary(
                'product.ProductService',
                'CheckInventory',
                $request,
                $context
            )->await();
            
            if (!$response->getAvailable()) {
                throw new GrpcException(
                    "Product {$item['product_id']} out of stock",
                    Status::FAILED_PRECONDITION
                );
            }
        }
        
        $span->end();
    }
}
```

## 服务发现与注册

### Consul 集成

```php
<?php
// app/Services/ServiceRegistry.php

namespace App\Services;

use HybridPHP\Core\Grpc\Discovery\ConsulServiceDiscovery;

class ServiceRegistry
{
    private ConsulServiceDiscovery $consul;
    
    public function __construct(array $config)
    {
        $this->consul = new ConsulServiceDiscovery($config);
    }
    
    public function register(string $serviceName, array $instance): void
    {
        $this->consul->register($serviceName, [
            'id' => $instance['id'],
            'host' => $instance['host'],
            'port' => $instance['port'],
            'tags' => $instance['tags'] ?? [],
            'meta' => $instance['meta'] ?? [],
            'check' => [
                'grpc' => "{$instance['host']}:{$instance['port']}",
                'interval' => '10s',
                'timeout' => '5s',
            ],
        ])->await();
    }
    
    public function deregister(string $serviceName, string $instanceId): void
    {
        $this->consul->deregister($serviceName, $instanceId)->await();
    }
    
    public function discover(string $serviceName): array
    {
        return $this->consul->discover($serviceName)->await();
    }
    
    public function watch(string $serviceName, callable $callback): void
    {
        $this->consul->watch($serviceName, $callback);
    }
}
```

### 服务启动注册

```php
<?php
// services/user-service/bootstrap.php

$container = new Container();
$config = require 'config/service.php';

// 注册服务提供者
$provider = new GrpcServiceProvider($container, $config['grpc']);
$provider->register();

// 创建 gRPC 服务器
$server = $container->get(GrpcServer::class);
$server->registerService('user.UserService', new UserService($container));

// 注册到 Consul
$registry = new ServiceRegistry($config['consul']);
$registry->register('user-service', [
    'id' => gethostname() . '-' . getmypid(),
    'host' => $config['host'],
    'port' => $config['port'],
    'tags' => ['grpc', 'user'],
]);

// 优雅关闭时注销
register_shutdown_function(function () use ($registry) {
    $registry->deregister('user-service', gethostname() . '-' . getmypid());
});

// 启动服务器
$server->start()->await();
```

## 配置中心

### Consul KV 配置

```php
<?php
// app/Config/RemoteConfig.php

namespace App\Config;

class RemoteConfig
{
    private ConsulClient $consul;
    private array $cache = [];
    
    public function get(string $key, $default = null)
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        
        $value = $this->consul->kv()->get("config/{$key}");
        
        if ($value === null) {
            return $default;
        }
        
        $this->cache[$key] = json_decode($value, true);
        return $this->cache[$key];
    }
    
    public function watch(string $key, callable $callback): void
    {
        $this->consul->kv()->watch("config/{$key}", function ($value) use ($callback, $key) {
            $this->cache[$key] = json_decode($value, true);
            $callback($this->cache[$key]);
        });
    }
}
```

## 熔断与降级

### 熔断器实现

```php
<?php
// app/CircuitBreaker/CircuitBreaker.php

namespace App\CircuitBreaker;

class CircuitBreaker
{
    private string $name;
    private int $failureThreshold;
    private int $successThreshold;
    private int $timeout;
    private string $state = 'closed';
    private int $failureCount = 0;
    private int $successCount = 0;
    private ?int $lastFailureTime = null;
    
    public function __construct(string $name, array $config = [])
    {
        $this->name = $name;
        $this->failureThreshold = $config['failure_threshold'] ?? 5;
        $this->successThreshold = $config['success_threshold'] ?? 3;
        $this->timeout = $config['timeout'] ?? 30;
    }
    
    public function call(callable $action, callable $fallback = null)
    {
        if ($this->state === 'open') {
            if (time() - $this->lastFailureTime >= $this->timeout) {
                $this->state = 'half-open';
            } else {
                return $fallback ? $fallback() : throw new CircuitBreakerOpenException();
            }
        }
        
        try {
            $result = $action();
            $this->onSuccess();
            return $result;
        } catch (\Throwable $e) {
            $this->onFailure();
            
            if ($fallback) {
                return $fallback();
            }
            
            throw $e;
        }
    }
    
    private function onSuccess(): void
    {
        $this->failureCount = 0;
        
        if ($this->state === 'half-open') {
            $this->successCount++;
            
            if ($this->successCount >= $this->successThreshold) {
                $this->state = 'closed';
                $this->successCount = 0;
            }
        }
    }
    
    private function onFailure(): void
    {
        $this->failureCount++;
        $this->lastFailureTime = time();
        
        if ($this->state === 'half-open') {
            $this->state = 'open';
            $this->successCount = 0;
        } elseif ($this->failureCount >= $this->failureThreshold) {
            $this->state = 'open';
        }
    }
}
```

### 使用熔断器

```php
$breaker = new CircuitBreaker('user-service', [
    'failure_threshold' => 5,
    'success_threshold' => 3,
    'timeout' => 30,
]);

$user = $breaker->call(
    fn() => $userClient->getUser($userId),
    fn() => $this->getCachedUser($userId) // 降级方案
);
```

## 分布式事务

### Saga 模式

```php
<?php
// app/Saga/CreateOrderSaga.php

namespace App\Saga;

class CreateOrderSaga
{
    private array $steps = [];
    private array $compensations = [];
    
    public function __construct(
        private OrderService $orderService,
        private InventoryService $inventoryService,
        private PaymentService $paymentService
    ) {
        $this->defineSteps();
    }
    
    private function defineSteps(): void
    {
        // 步骤1：创建订单
        $this->addStep(
            fn($ctx) => $this->orderService->createOrder($ctx['order_data']),
            fn($ctx) => $this->orderService->cancelOrder($ctx['order_id'])
        );
        
        // 步骤2：扣减库存
        $this->addStep(
            fn($ctx) => $this->inventoryService->deduct($ctx['items']),
            fn($ctx) => $this->inventoryService->restore($ctx['items'])
        );
        
        // 步骤3：处理支付
        $this->addStep(
            fn($ctx) => $this->paymentService->charge($ctx['payment_data']),
            fn($ctx) => $this->paymentService->refund($ctx['payment_id'])
        );
    }
    
    public function execute(array $context): array
    {
        $executedSteps = [];
        
        try {
            foreach ($this->steps as $index => $step) {
                $result = $step($context);
                $context = array_merge($context, $result);
                $executedSteps[] = $index;
            }
            
            return $context;
            
        } catch (\Throwable $e) {
            // 执行补偿
            foreach (array_reverse($executedSteps) as $index) {
                try {
                    $this->compensations[$index]($context);
                } catch (\Throwable $compensationError) {
                    // 记录补偿失败，人工介入
                    $this->logCompensationFailure($index, $compensationError);
                }
            }
            
            throw $e;
        }
    }
    
    private function addStep(callable $action, callable $compensation): void
    {
        $this->steps[] = $action;
        $this->compensations[] = $compensation;
    }
}
```

## 部署配置

### Docker Compose

```yaml
version: '3.8'

services:
  api-gateway:
    image: hybridphp/api-gateway
    ports:
      - "8443:8443"
    environment:
      - CONSUL_HOST=consul
    depends_on:
      - consul

  user-service:
    image: hybridphp/user-service
    environment:
      - CONSUL_HOST=consul
      - DB_HOST=mysql-user
    depends_on:
      - consul
      - mysql-user

  order-service:
    image: hybridphp/order-service
    environment:
      - CONSUL_HOST=consul
      - DB_HOST=mysql-order
    depends_on:
      - consul
      - mysql-order

  product-service:
    image: hybridphp/product-service
    environment:
      - CONSUL_HOST=consul
      - MONGO_HOST=mongodb
    depends_on:
      - consul
      - mongodb

  consul:
    image: consul:latest
    ports:
      - "8500:8500"

  jaeger:
    image: jaegertracing/all-in-one:latest
    ports:
      - "16686:16686"
      - "14268:14268"

  prometheus:
    image: prom/prometheus
    ports:
      - "9090:9090"
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml

  grafana:
    image: grafana/grafana
    ports:
      - "3000:3000"
```

### Kubernetes

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: user-service
spec:
  replicas: 3
  selector:
    matchLabels:
      app: user-service
  template:
    metadata:
      labels:
        app: user-service
      annotations:
        prometheus.io/scrape: "true"
        prometheus.io/port: "9090"
    spec:
      containers:
      - name: user-service
        image: hybridphp/user-service:latest
        ports:
        - containerPort: 50051
        env:
        - name: CONSUL_HOST
          value: consul.default.svc.cluster.local
        readinessProbe:
          grpc:
            port: 50051
          initialDelaySeconds: 5
        livenessProbe:
          grpc:
            port: 50051
          initialDelaySeconds: 10
```

## 监控与告警

### Prometheus 指标

```php
// 请求计数
$counter->inc('grpc_requests_total', [
    'service' => 'user-service',
    'method' => 'GetUser',
    'status' => 'success',
]);

// 请求延迟
$histogram->observe('grpc_request_duration_seconds', $duration, [
    'service' => 'user-service',
    'method' => 'GetUser',
]);

// 熔断器状态
$gauge->set('circuit_breaker_state', $state, [
    'name' => 'user-service',
]);
```

### 告警规则

```yaml
groups:
- name: microservices
  rules:
  - alert: ServiceDown
    expr: up{job="grpc-services"} == 0
    for: 1m
    labels:
      severity: critical
    annotations:
      summary: "Service {{ $labels.instance }} is down"

  - alert: HighErrorRate
    expr: rate(grpc_requests_total{status="error"}[5m]) > 0.1
    for: 5m
    labels:
      severity: warning
    annotations:
      summary: "High error rate on {{ $labels.service }}"

  - alert: CircuitBreakerOpen
    expr: circuit_breaker_state == 1
    for: 1m
    labels:
      severity: warning
    annotations:
      summary: "Circuit breaker open for {{ $labels.name }}"
```

## 下一步

- [API 网关](./API_GATEWAY.md) - 网关详细设计
- [分布式追踪](../advanced/TRACING.md) - 追踪系统详解
- [部署运维](../deployment/KUBERNETES.md) - K8s 部署指南
