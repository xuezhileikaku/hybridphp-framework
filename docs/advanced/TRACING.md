# 分布式追踪

HybridPHP 提供完整的分布式追踪支持，兼容 OpenTelemetry 标准，支持 Jaeger、Zipkin 等主流追踪系统。

## 核心特性

- **OpenTelemetry 兼容**: 支持 OTLP 协议
- **多种导出器**: Jaeger、Zipkin、Console、OTLP
- **上下文传播**: W3C Trace Context、B3、Jaeger 格式
- **自动埋点**: HTTP 请求、数据库查询自动追踪
- **采样策略**: 支持概率采样、速率限制采样

## 快速开始

### 基础配置

```php
// config/tracing.php
return [
    'enabled' => true,
    'service_name' => 'my-application',
    
    'exporter' => [
        'type' => 'jaeger',  // jaeger, zipkin, otlp, console
        'host' => 'localhost',
        'port' => 14268,
    ],
    
    'tracer' => [
        'batch_size' => 50,
        'sampling_rate' => 1.0,  // 100% 采样
    ],
    
    'http' => [
        'excluded_paths' => ['/health', '/metrics'],
    ],
];
```

### 创建 Tracer

```php
use HybridPHP\Core\Tracing\Tracer;
use HybridPHP\Core\Tracing\Exporter\JaegerExporter;
use HybridPHP\Core\Tracing\Propagation\CompositePropagator;

$tracer = new Tracer(
    'order-service',
    JaegerExporter::create('order-service', 'localhost', 14268),
    CompositePropagator::createDefault()
);
```

## 基础使用

### 创建 Span

```php
// 开始一个追踪
$rootSpan = $tracer->startTrace('process-order', [
    'order.id' => 'ORD-12345',
    'customer.id' => 'CUST-789',
]);

// 添加事件
$rootSpan->addEvent('order.received');

// 创建子 Span
$validateSpan = $tracer->startSpan('validate-order');
$validateSpan->setAttribute('validation.rules', 5);
// ... 执行验证逻辑
$validateSpan->setStatus(SpanStatus::OK);
$validateSpan->end();

// 完成根 Span
$rootSpan->setStatus(SpanStatus::OK);
$rootSpan->end();

// 刷新数据到导出器
$tracer->flush();
```

### Span 属性

```php
use HybridPHP\Core\Tracing\SpanStatus;
use HybridPHP\Core\Tracing\SpanKind;

$span = $tracer->startSpan('http-request', [
    'http.method' => 'POST',
    'http.url' => '/api/orders',
]);

// 设置 Span 类型
$span->setKind(SpanKind::SERVER);

// 批量设置属性
$span->setAttributes([
    'http.status_code' => 200,
    'http.response_size' => 1024,
]);

// 添加事件
$span->addEvent('request.validated', [
    'validation_time_ms' => 15,
]);

// 设置状态
$span->setStatus(SpanStatus::OK, 'Request processed successfully');

$span->end();
```

### 错误处理

```php
$span = $tracer->startSpan('risky-operation');

try {
    // 执行可能失败的操作
    $result = $this->riskyOperation();
    $span->setStatus(SpanStatus::OK);
} catch (Throwable $e) {
    // 记录异常
    $span->recordException($e);
    // 状态自动设置为 ERROR
    throw $e;
} finally {
    $span->end();
}
```

## 上下文传播

### 提取上下文

```php
// 从 HTTP 请求头提取追踪上下文
$incomingHeaders = [
    'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
    'tracestate' => 'congo=t61rcWkgMzE',
];

$parentContext = $tracer->extract($incomingHeaders);

if ($parentContext !== null) {
    echo "Trace ID: " . $parentContext->getTraceId() . "\n";
    echo "Span ID: " . $parentContext->getSpanId() . "\n";
}

// 使用父上下文创建子 Span
$span = $tracer->startSpan('handle-request', [], $parentContext);
```

### 注入上下文

```php
// 注入追踪上下文到出站请求
$outgoingHeaders = [];
$tracer->inject($outgoingHeaders);

// 发送 HTTP 请求时携带追踪头
$client->request('GET', $url, [
    'headers' => $outgoingHeaders
]);
```

### 传播格式

| 格式 | 说明 | 头部 |
|------|------|------|
| W3C Trace Context | 标准格式 | `traceparent`, `tracestate` |
| B3 | Zipkin 格式 | `X-B3-TraceId`, `X-B3-SpanId` |
| Jaeger | Jaeger 格式 | `uber-trace-id` |

```php
use HybridPHP\Core\Tracing\Propagation\W3CTraceContextPropagator;
use HybridPHP\Core\Tracing\Propagation\B3Propagator;
use HybridPHP\Core\Tracing\Propagation\JaegerPropagator;
use HybridPHP\Core\Tracing\Propagation\CompositePropagator;

// 使用复合传播器支持多种格式
$propagator = new CompositePropagator([
    new W3CTraceContextPropagator(),
    new B3Propagator(),
    new JaegerPropagator(),
]);
```

## 导出器配置

### Jaeger

```php
use HybridPHP\Core\Tracing\Exporter\JaegerExporter;

$exporter = JaegerExporter::create(
    'my-service',
    'jaeger.example.com',
    14268
);

// 配置文件方式
'exporter' => [
    'type' => 'jaeger',
    'host' => 'jaeger.example.com',
    'port' => 14268,
],
```

### Zipkin

```php
use HybridPHP\Core\Tracing\Exporter\ZipkinExporter;

$exporter = ZipkinExporter::create(
    'my-service',
    'zipkin.example.com',
    9411
);

// 配置文件方式
'exporter' => [
    'type' => 'zipkin',
    'host' => 'zipkin.example.com',
    'port' => 9411,
],
```

### OTLP (OpenTelemetry Protocol)

```php
use HybridPHP\Core\Tracing\Exporter\OtlpExporter;

$exporter = OtlpExporter::create(
    'my-service',
    'otel-collector.example.com',
    4318
);

// 配置文件方式
'exporter' => [
    'type' => 'otlp',
    'host' => 'otel-collector.example.com',
    'port' => 4318,
],
```

### Console (调试用)

```php
use HybridPHP\Core\Tracing\Exporter\ConsoleExporter;

$exporter = new ConsoleExporter(true); // pretty print

// 配置文件方式
'exporter' => [
    'type' => 'console',
    'pretty_print' => true,
],
```

## 中间件集成

### HTTP 追踪中间件

```php
use HybridPHP\Core\Tracing\Middleware\TracingMiddleware;

// 自动追踪所有 HTTP 请求
$middleware = new TracingMiddleware($tracer, [
    'excluded_paths' => ['/health', '/metrics'],
    'record_headers' => true,
    'record_body' => false,
]);

$app->addMiddleware($middleware);
```

### 数据库追踪中间件

```php
use HybridPHP\Core\Tracing\Middleware\DatabaseTracingMiddleware;

// 自动追踪数据库查询
$dbMiddleware = new DatabaseTracingMiddleware($tracer, [
    'record_statement' => true,
    'record_parameters' => false,  // 生产环境建议关闭
]);
```

## 采样策略

### 概率采样

```php
// 50% 采样率
$tracer = new Tracer(
    'my-service',
    $exporter,
    null,
    null,
    ['sampling_rate' => 0.5]
);
```

### 自定义采样

```php
// 根据请求特征决定是否采样
$sampler = function ($spanName, $attributes) {
    // 错误请求总是采样
    if (isset($attributes['error']) && $attributes['error']) {
        return true;
    }
    
    // 特定路径总是采样
    if (str_starts_with($attributes['http.url'] ?? '', '/api/critical')) {
        return true;
    }
    
    // 其他请求 10% 采样
    return random_int(1, 100) <= 10;
};
```

## 服务提供者

```php
use HybridPHP\Core\Tracing\TracingServiceProvider;

$container = new Container();

$provider = new TracingServiceProvider($container, [
    'enabled' => true,
    'service_name' => 'my-application',
    'exporter' => [
        'type' => 'jaeger',
        'host' => 'localhost',
        'port' => 14268,
    ],
]);

$provider->register();

// 获取 Tracer
$tracer = $container->get(Tracer::class);
```

## 实战示例

### 微服务调用追踪

```php
class OrderService
{
    public function __construct(
        private Tracer $tracer,
        private HttpClient $httpClient
    ) {}

    public function createOrder(array $orderData): Order
    {
        $span = $this->tracer->startTrace('create-order', [
            'order.items_count' => count($orderData['items']),
        ]);

        try {
            // 验证库存
            $this->checkInventory($orderData['items']);
            
            // 处理支付
            $this->processPayment($orderData);
            
            // 创建订单
            $order = $this->saveOrder($orderData);
            
            $span->setStatus(SpanStatus::OK);
            return $order;
        } catch (Throwable $e) {
            $span->recordException($e);
            throw $e;
        } finally {
            $span->end();
            $this->tracer->flush();
        }
    }

    private function checkInventory(array $items): void
    {
        $span = $this->tracer->startSpan('check-inventory');
        
        // 调用库存服务
        $headers = [];
        $this->tracer->inject($headers);
        
        $response = $this->httpClient->post(
            'http://inventory-service/check',
            ['items' => $items],
            ['headers' => $headers]
        );
        
        $span->setAttribute('inventory.available', $response['available']);
        $span->end();
    }

    private function processPayment(array $orderData): void
    {
        $span = $this->tracer->startSpan('process-payment');
        $span->setAttribute('payment.amount', $orderData['total']);
        
        // 调用支付服务
        $headers = [];
        $this->tracer->inject($headers);
        
        $response = $this->httpClient->post(
            'http://payment-service/charge',
            ['amount' => $orderData['total']],
            ['headers' => $headers]
        );
        
        $span->setAttribute('payment.transaction_id', $response['transaction_id']);
        $span->end();
    }
}
```

### 追踪可视化

在 Jaeger UI 中查看追踪：

```
create-order (OrderService) ─────────────────────────────────────
    │
    ├── check-inventory (InventoryService) ──────────
    │
    ├── process-payment (PaymentService) ────────────────────
    │
    └── save-order (Database) ───────
```

## 最佳实践

### 1. 合理命名 Span

```php
// ✅ 好的命名
$tracer->startSpan('http.request');
$tracer->startSpan('db.query');
$tracer->startSpan('cache.get');

// ❌ 避免的命名
$tracer->startSpan('span1');
$tracer->startSpan('do_something');
```

### 2. 添加有意义的属性

```php
$span->setAttributes([
    'http.method' => 'POST',
    'http.url' => '/api/orders',
    'http.status_code' => 200,
    'user.id' => $userId,
    'order.id' => $orderId,
]);
```

### 3. 记录关键事件

```php
$span->addEvent('order.validated');
$span->addEvent('payment.authorized', [
    'transaction_id' => $txId,
]);
$span->addEvent('order.completed');
```

### 4. 生产环境配置

```php
'tracing' => [
    'enabled' => true,
    'sampling_rate' => 0.1,  // 10% 采样
    'exporter' => [
        'type' => 'otlp',
        'host' => 'otel-collector',
        'port' => 4318,
    ],
    'http' => [
        'excluded_paths' => ['/health', '/metrics', '/favicon.ico'],
        'record_headers' => false,
        'record_body' => false,
    ],
],
```

## 与监控系统集成

### Prometheus + Grafana

追踪数据可以与 Prometheus 指标关联：

```php
// 在 Span 中记录指标
$span->setAttribute('metrics.request_duration_ms', $duration);
$span->setAttribute('metrics.db_queries_count', $queryCount);
```

### ELK Stack

追踪 ID 可以关联到日志：

```php
$logger->info('Order created', [
    'order_id' => $orderId,
    'trace_id' => $span->getContext()->getTraceId(),
    'span_id' => $span->getContext()->getSpanId(),
]);
```

## 下一步

- [IM 即时通讯系统](../applications/IM_SYSTEM.md) - 追踪在 IM 系统中的应用
- [微服务架构](../applications/MICROSERVICES.md) - 分布式系统追踪
- [监控告警](../deployment/MONITORING.md) - 监控系统集成
