# HybridPHP Async Logging System

## Overview

The HybridPHP Async Logging System is a comprehensive, high-performance logging solution that provides:

- **Async batch processing** to avoid blocking the main application flow
- **Structured JSON logging** with rich context information
- **Distributed tracing** support for microservices architecture
- **Multiple output targets** (file, ELK, Kafka, syslog, etc.)
- **Automatic log archiving** with compression and cleanup
- **PSR-3 compatibility** for seamless integration

## Key Features

### 1. Async Batch Processing
- Non-blocking log writes using AMPHP coroutines
- Configurable buffer sizes and flush intervals
- Automatic batching to optimize I/O performance
- Graceful error handling without affecting application flow

### 2. Structured Logging
- JSON format with consistent structure
- Rich context including trace IDs, timestamps, memory usage
- Automatic sensitive data filtering
- Configurable field limits and depth restrictions

### 3. Distributed Tracing
- W3C Trace Context standard support
- Jaeger and Zipkin compatibility
- Automatic span creation and management
- Cross-service trace propagation

### 4. Multiple Output Targets
- File logging with rotation
- Elasticsearch (ELK) integration
- Apache Kafka streaming
- Syslog support
- Standard error output
- Stack channels for multiple outputs

### 5. Log Archiving
- Automatic file rotation based on size/time
- Compression (gzip/zip) for old logs
- Configurable retention policies
- Background cleanup processes

## Architecture

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│   Application   │───▶│   AsyncLogger    │───▶│   Log Handlers  │
│                 │    │                  │    │                 │
│ - Controllers   │    │ - Buffer         │    │ - File          │
│ - Services      │    │ - Batch Process  │    │ - ELK           │
│ - Middleware    │    │ - Async Flush    │    │ - Kafka         │
└─────────────────┘    └──────────────────┘    └─────────────────┘
                                │
                                ▼
                       ┌──────────────────┐
                       │ DistributedTrace │
                       │                  │
                       │ - Trace Context  │
                       │ - Span Management│
                       │ - Baggage        │
                       └──────────────────┘
```

## Configuration

### Basic Configuration (`config/logging.php`)

```php
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
    
    'tracing' => [
        'enabled' => true,
        'sample_rate' => 1.0,
        'service_name' => 'hybridphp',
    ],
    
    'archive' => [
        'enabled' => true,
        'max_files' => 30,
        'max_size' => 10 * 1024 * 1024, // 10MB
        'compress' => true,
    ],
];
```

## Usage Examples

### Basic Logging

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
                'email' => $user->getEmail(),
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

### Distributed Tracing

```php
use HybridPHP\Core\Logging\DistributedTracing;

class OrderService
{
    public function processOrder(Order $order): void
    {
        // Start a new span for order processing
        $spanId = DistributedTracing::startSpan('process_order', [
            'order_id' => $order->getId(),
            'customer_id' => $order->getCustomerId(),
        ]);
        
        try {
            // Process payment
            $this->processPayment($order);
            
            // Update inventory
            $this->updateInventory($order);
            
            // Send confirmation
            $this->sendConfirmation($order);
            
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
        
        // Payment processing logic...
        
        DistributedTracing::finishSpan(['amount' => $order->getTotal()]);
    }
}
```

### Custom Log Channels

```php
use HybridPHP\Core\Logging\LogManager;

class SecurityService
{
    public function __construct(private LogManager $logManager) {}
    
    public function logSecurityEvent(string $event, array $context = []): void
    {
        // Create a dedicated security logger
        $securityLogger = $this->logManager->createCustomLogger('security', [
            'driver' => 'stack',
            'channels' => ['file', 'elk'],
            'path' => 'storage/logs/security.log',
            'level' => 'warning',
        ]);
        
        $securityLogger->warning($event, array_merge($context, [
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            'timestamp' => time(),
        ]));
    }
}
```

### Middleware Integration

```php
use HybridPHP\Core\Logging\TracingMiddleware;

// In your middleware stack
$app->addMiddleware(new TracingMiddleware($logger, [
    'log_requests' => true,
    'log_responses' => true,
    'log_headers' => false,
    'sensitive_headers' => ['authorization', 'cookie'],
]));
```

## Log Format

### Standard Log Entry

```json
{
    "message": "User login successful",
    "context": {
        "user_id": 12345,
        "email": "user@example.com",
        "ip_address": "192.168.1.100",
        "trace_id": "9fe5437da6c7432e",
        "timestamp": 1753324969.038861,
        "memory_usage": 4194304,
        "process_id": 21844
    },
    "level": 200,
    "level_name": "INFO",
    "channel": "app",
    "datetime": "2025-07-24T02:42:49.039429+00:00",
    "extra": {}
}
```

### Trace Context

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
        "http.status_code": 200,
        "user_id": 12345
    },
    "logs": [
        {
            "timestamp": 1753324969.045,
            "message": "Validating credentials",
            "fields": {"step": "validation"}
        }
    ]
}
```

## Performance Considerations

### Buffer Management
- Default buffer size: 1000 entries
- Automatic flush every 5 seconds
- Immediate flush on buffer full
- Memory-efficient batch processing

### Async Processing
- Non-blocking I/O operations
- Coroutine-based processing
- Graceful error handling
- Resource cleanup on shutdown

### File I/O Optimization
- Batch writes to reduce system calls
- Compression for archived logs
- Automatic rotation to prevent large files
- Background cleanup processes

## Monitoring and Metrics

### Logger Statistics

```php
$stats = $logger->getStats();
// Returns:
// [
//     'buffer_size' => 55,
//     'max_buffer_size' => 1000,
//     'flush_interval' => 5.0,
//     'auto_flush' => true,
//     'running' => true,
//     'trace_id' => '24d151639b26d601...'
// ]
```

### Archive Statistics

```php
$archiver = $container->get(LogArchiver::class);
$stats = $archiver->getStats();
// Returns:
// [
//     'total_files' => 15,
//     'compressed_files' => 10,
//     'total_size' => 52428800,
//     'total_size_human' => '50.0 MB'
// ]
```

## Integration with External Systems

### Elasticsearch (ELK Stack)

The ELK handler automatically formats logs for Elasticsearch ingestion:

```php
'elk' => [
    'driver' => 'elk',
    'host' => 'elasticsearch.example.com',
    'port' => 9200,
    'scheme' => 'https',
    'index' => 'hybridphp-logs-{date}',
    'auth' => [
        'username' => 'elastic',
        'password' => 'secret'
    ]
]
```

### Apache Kafka

Stream logs to Kafka for real-time processing:

```php
'kafka' => [
    'driver' => 'kafka',
    'brokers' => ['kafka1:9092', 'kafka2:9092'],
    'topic' => 'application-logs',
    'buffer_size' => 100
]
```

### Prometheus Metrics

The logging system exposes metrics for monitoring:

- `hybridphp_log_entries_total` - Total log entries by level
- `hybridphp_log_buffer_size` - Current buffer size
- `hybridphp_log_flush_duration` - Time taken for flush operations

## Best Practices

### 1. Use Appropriate Log Levels
- **DEBUG**: Detailed diagnostic information
- **INFO**: General application flow
- **WARNING**: Potentially harmful situations
- **ERROR**: Error events that don't stop execution
- **CRITICAL**: Critical conditions

### 2. Include Relevant Context
```php
$logger->info('Order processed', [
    'order_id' => $order->getId(),
    'customer_id' => $order->getCustomerId(),
    'amount' => $order->getTotal(),
    'processing_time' => $processingTime,
]);
```

### 3. Use Structured Data
```php
// Good
$logger->error('Database connection failed', [
    'host' => $config['host'],
    'port' => $config['port'],
    'error_code' => $e->getCode(),
]);

// Avoid
$logger->error("Database connection failed to {$config['host']}:{$config['port']} with error {$e->getCode()}");
```

### 4. Implement Trace Context
```php
// Extract trace context from incoming requests
DistributedTracing::extractFromHeaders($request->getHeaders());

// Add trace context to outgoing requests
$headers = DistributedTracing::injectIntoHeaders();
$client->request('GET', $url, ['headers' => $headers]);
```

### 5. Monitor Performance
```php
// Regular monitoring of logger performance
$stats = $logger->getStats();
if ($stats['buffer_size'] > $stats['max_buffer_size'] * 0.8) {
    // Consider increasing flush frequency or buffer size
}
```

## Troubleshooting

### Common Issues

1. **Logs not appearing**: Check buffer flush settings and ensure proper shutdown
2. **High memory usage**: Reduce buffer size or increase flush frequency
3. **Slow performance**: Enable async processing and optimize handlers
4. **Missing trace context**: Ensure proper middleware order and header extraction

### Debug Mode

Enable debug logging to troubleshoot issues:

```php
'channels' => [
    'debug' => [
        'driver' => 'stderr',
        'level' => 'debug',
    ]
]
```

## Conclusion

The HybridPHP Async Logging System provides a robust, scalable solution for application logging with modern features like distributed tracing, async processing, and multiple output targets. It's designed to handle high-throughput applications while maintaining excellent performance and reliability.