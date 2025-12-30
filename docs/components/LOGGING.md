# 日志系统

HybridPHP 提供高性能异步日志系统，支持结构化日志、分布式追踪、多输出目标和自动归档。

## 核心特性

- **异步批处理**: 非阻塞日志写入
- **结构化日志**: JSON 格式，丰富上下文
- **分布式追踪**: W3C Trace Context 标准
- **多输出目标**: 文件、ELK、Kafka、Syslog
- **自动归档**: 压缩和清理旧日志
- **PSR-3 兼容**: 标准日志接口

## 快速开始

### 配置

```php
// config/logging.php
return [
    'default' => 'file',
    
    'channels' => [
        'file' => [
            'driver' => 'file',
            'path' => 'storage/logs/app.log',
            'level' => 'debug',
        ],
        
        'elk' => [
            'driver' => 'elk',
            'host' => 'localhost',
            'port' => 9200,
            'index' => 'hybridphp-logs',
            'level' => 'info',
        ],
        
        'kafka' => [
            'driver' => 'kafka',
            'brokers' => ['localhost:9092'],
            'topic' => 'hybridphp-logs',
            'level' => 'info',
        ],
    ],
    
    'async' => [
        'enabled' => true,
        'buffer_size' => 1000,
        'flush_interval' => 5.0,
    ],
    
    'archive' => [
        'enabled' => true,
        'max_files' => 30,
        'max_size' => 10 * 1024 * 1024,
        'compress' => true,
    ],
];
```

### 基础使用

```php
use Psr\Log\LoggerInterface;

class UserController
{
    public function __construct(private LoggerInterface $logger) {}
    
    public function createUser(array $userData): User
    {
        $this->logger->info('Creating new user', [
            'user_data' => $userData,
            'ip_address' => $_SERVER['REMOTE_ADDR'],
        ]);
        
        try {
            $user = $this->userService->create($userData);
            
            $this->logger->info('User created successfully', [
                'user_id' => $user->getId(),
            ]);
            
            return $user;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create user', [
                'error' => $e->getMessage(),
                'user_data' => $userData,
            ]);
            
            throw $e;
        }
    }
}
```

## 日志级别

| 级别 | 说明 | 使用场景 |
|------|------|---------|
| DEBUG | 详细调试信息 | 开发调试 |
| INFO | 一般信息 | 业务流程记录 |
| NOTICE | 正常但重要事件 | 需要关注的事件 |
| WARNING | 警告信息 | 潜在问题 |
| ERROR | 错误事件 | 不影响运行的错误 |
| CRITICAL | 严重错误 | 需要立即处理 |
| ALERT | 需要立即行动 | 系统不可用 |
| EMERGENCY | 系统不可用 | 紧急情况 |

## 分布式追踪

```php
use HybridPHP\Core\Logging\DistributedTracing;

class OrderService
{
    public function processOrder(Order $order): void
    {
        // 开始追踪 Span
        $spanId = DistributedTracing::startSpan('process_order', [
            'order_id' => $order->getId(),
        ]);
        
        try {
            $this->processPayment($order);
            $this->updateInventory($order);
            
            DistributedTracing::finishSpan(['success' => true]);
        } catch (\Exception $e) {
            DistributedTracing::setTag('error', true);
            DistributedTracing::logToSpan('Order processing failed', [
                'error' => $e->getMessage(),
            ]);
            
            DistributedTracing::finishSpan(['success' => false]);
            throw $e;
        }
    }
    
    private function processPayment(Order $order): void
    {
        $spanId = DistributedTracing::startSpan('process_payment');
        // 支付处理...
        DistributedTracing::finishSpan(['amount' => $order->getTotal()]);
    }
}
```

## 追踪中间件

```php
use HybridPHP\Core\Logging\TracingMiddleware;

$app->addMiddleware(new TracingMiddleware($logger, [
    'log_requests' => true,
    'log_responses' => true,
    'log_headers' => false,
    'sensitive_headers' => ['authorization', 'cookie'],
]));
```

## 日志格式

### 标准日志条目

```json
{
    "message": "User login successful",
    "context": {
        "user_id": 12345,
        "email": "user@example.com",
        "ip_address": "192.168.1.100",
        "trace_id": "9fe5437da6c7432e",
        "timestamp": 1753324969.038861,
        "memory_usage": 4194304
    },
    "level": 200,
    "level_name": "INFO",
    "channel": "app",
    "datetime": "2025-07-24T02:42:49.039429+00:00"
}
```

### 追踪上下文

```json
{
    "trace_id": "9fe5437da6c7432e",
    "span_id": "a1b2c3d4e5f6",
    "parent_span_id": "f6e5d4c3b2a1",
    "operation_name": "user_authentication",
    "start_time": 1753324969.038,
    "end_time": 1753324969.142,
    "duration": 0.104,
    "tags": {
        "http.method": "POST",
        "http.status_code": 200
    }
}
```

## 自定义日志通道

```php
use HybridPHP\Core\Logging\LogManager;

class SecurityService
{
    public function __construct(private LogManager $logManager) {}
    
    public function logSecurityEvent(string $event, array $context = []): void
    {
        $securityLogger = $this->logManager->createCustomLogger('security', [
            'driver' => 'stack',
            'channels' => ['file', 'elk'],
            'path' => 'storage/logs/security.log',
            'level' => 'warning',
        ]);
        
        $securityLogger->warning($event, array_merge($context, [
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'timestamp' => time(),
        ]));
    }
}
```

## 监控统计

```php
// 获取日志统计
$stats = $logger->getStats();
// [
//     'buffer_size' => 55,
//     'max_buffer_size' => 1000,
//     'flush_interval' => 5.0,
//     'running' => true,
// ]

// 归档统计
$archiver = $container->get(LogArchiver::class);
$stats = $archiver->getStats();
// [
//     'total_files' => 15,
//     'compressed_files' => 10,
//     'total_size' => 52428800,
//     'total_size_human' => '50.0 MB'
// ]
```

## 最佳实践

### 使用结构化数据

```php
// ✅ 好的做法
$logger->error('Database connection failed', [
    'host' => $config['host'],
    'port' => $config['port'],
    'error_code' => $e->getCode(),
]);

// ❌ 避免
$logger->error("Database connection failed to {$host}:{$port}");
```

### 包含相关上下文

```php
$logger->info('Order processed', [
    'order_id' => $order->getId(),
    'customer_id' => $order->getCustomerId(),
    'amount' => $order->getTotal(),
    'processing_time' => $processingTime,
]);
```

### 传播追踪上下文

```php
// 从请求提取
DistributedTracing::extractFromHeaders($request->getHeaders());

// 注入到出站请求
$headers = DistributedTracing::injectIntoHeaders();
$client->request('GET', $url, ['headers' => $headers]);
```

## 下一步

- [分布式追踪](../advanced/TRACING.md) - 追踪系统详解
- [监控告警](../deployment/MONITORING.md) - 监控系统集成
