<?php

declare(strict_types=1);

/**
 * HybridPHP Distributed Tracing Usage Examples
 * 
 * This file demonstrates how to use the distributed tracing system
 * with OpenTelemetry, Jaeger, and Zipkin support.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HybridPHP\Core\Container;
use HybridPHP\Core\Tracing\Tracer;
use HybridPHP\Core\Tracing\SpanStatus;
use HybridPHP\Core\Tracing\SpanKind;
use HybridPHP\Core\Tracing\Exporter\ConsoleExporter;
use HybridPHP\Core\Tracing\Exporter\JaegerExporter;
use HybridPHP\Core\Tracing\Exporter\ZipkinExporter;
use HybridPHP\Core\Tracing\Exporter\OtlpExporter;
use HybridPHP\Core\Tracing\Propagation\CompositePropagator;
use HybridPHP\Core\Tracing\TracingServiceProvider;

echo "=== HybridPHP Distributed Tracing Examples ===\n\n";

// Example 1: Basic tracing with console output
echo "1. Basic Tracing with Console Output\n";
echo str_repeat('-', 50) . "\n";

$tracer = new Tracer(
    'example-service',
    new ConsoleExporter(true),
    CompositePropagator::createDefault()
);

// Start a trace
$rootSpan = $tracer->startTrace('process-order', [
    'order.id' => 'ORD-12345',
    'customer.id' => 'CUST-789',
]);

// Add events
$rootSpan->addEvent('order.received');

// Create child spans
$validateSpan = $tracer->startSpan('validate-order');
$validateSpan->setAttribute('validation.rules', 5);
usleep(10000); // Simulate work
$validateSpan->setStatus(SpanStatus::OK);
$validateSpan->end();

$paymentSpan = $tracer->startSpan('process-payment');
$paymentSpan->setAttributes([
    'payment.method' => 'credit_card',
    'payment.amount' => 99.99,
]);
usleep(20000); // Simulate work
$paymentSpan->addEvent('payment.authorized');
$paymentSpan->setStatus(SpanStatus::OK);
$paymentSpan->end();

// Complete root span
$rootSpan->addEvent('order.completed');
$rootSpan->setStatus(SpanStatus::OK);
$rootSpan->end();

// Flush spans
$tracer->flush();

echo "\n";

// Example 2: Error handling in spans
echo "2. Error Handling in Spans\n";
echo str_repeat('-', 50) . "\n";

$errorSpan = $tracer->startTrace('risky-operation');

try {
    $errorSpan->addEvent('operation.started');
    
    // Simulate an error
    throw new RuntimeException('Something went wrong!');
    
} catch (Throwable $e) {
    $errorSpan->recordException($e);
    // Status is automatically set to ERROR by recordException
}

$errorSpan->end();
$tracer->flush();

echo "\n";

// Example 3: Context propagation
echo "3. Context Propagation (HTTP Headers)\n";
echo str_repeat('-', 50) . "\n";

// Simulate incoming request with trace context
$incomingHeaders = [
    'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
    'tracestate' => 'congo=t61rcWkgMzE',
];

// Extract context from headers
$parentContext = $tracer->extract($incomingHeaders);

if ($parentContext !== null) {
    echo "Extracted trace context:\n";
    echo "  Trace ID: " . $parentContext->getTraceId() . "\n";
    echo "  Span ID: " . $parentContext->getSpanId() . "\n";
    echo "  Sampled: " . ($parentContext->isSampled() ? 'yes' : 'no') . "\n";
}

// Create span with parent context
$childSpan = $tracer->startSpan('handle-request', [], $parentContext);
$childSpan->setAttribute('http.method', 'POST');
$childSpan->end();

// Inject context into outgoing headers
$outgoingHeaders = [];
$tracer->inject($outgoingHeaders);

echo "\nInjected headers for outgoing request:\n";
foreach ($outgoingHeaders as $name => $value) {
    echo "  $name: $value\n";
}

$tracer->flush();

echo "\n";

// Example 4: Using different exporters
echo "4. Different Exporter Configurations\n";
echo str_repeat('-', 50) . "\n";

// Jaeger exporter
echo "Jaeger exporter configuration:\n";
$jaegerExporter = JaegerExporter::create(
    'my-service',
    'localhost',
    14268
);
echo "  Endpoint: http://localhost:14268/api/traces\n";

// Zipkin exporter
echo "\nZipkin exporter configuration:\n";
$zipkinExporter = ZipkinExporter::create(
    'my-service',
    'localhost',
    9411
);
echo "  Endpoint: http://localhost:9411/api/v2/spans\n";

// OTLP exporter
echo "\nOTLP exporter configuration:\n";
$otlpExporter = OtlpExporter::create(
    'my-service',
    'localhost',
    4318
);
echo "  Endpoint: http://localhost:4318/v1/traces\n";

echo "\n";

// Example 5: Service provider usage
echo "5. Service Provider Usage\n";
echo str_repeat('-', 50) . "\n";

$container = new Container();
$config = [
    'enabled' => true,
    'service_name' => 'my-application',
    'exporter' => [
        'type' => 'console',
        'pretty_print' => true,
    ],
    'tracer' => [
        'batch_size' => 50,
        'sampling_rate' => 1.0,
    ],
    'http' => [
        'excluded_paths' => ['/health', '/metrics'],
    ],
];

$provider = new TracingServiceProvider($container, $config);
$provider->register();

echo "Tracing service provider registered successfully.\n";
echo "Services available:\n";
echo "  - Tracer\n";
echo "  - TracingInterface\n";
echo "  - TracingMiddleware\n";
echo "  - DatabaseTracingMiddleware\n";

echo "\n";

// Example 6: Nested spans
echo "6. Nested Spans (Call Stack)\n";
echo str_repeat('-', 50) . "\n";

$tracer2 = new Tracer(
    'nested-example',
    new ConsoleExporter(true)
);

function processRequest(Tracer $tracer): void
{
    $span = $tracer->startTrace('http.request');
    $span->setAttribute('http.method', 'GET');
    $span->setAttribute('http.url', '/api/users');
    
    try {
        fetchUsers($tracer);
        $span->setStatus(SpanStatus::OK);
    } finally {
        $span->end();
    }
}

function fetchUsers(Tracer $tracer): void
{
    $span = $tracer->startSpan('db.query');
    $span->setAttribute('db.system', 'mysql');
    $span->setAttribute('db.statement', 'SELECT * FROM users');
    
    usleep(5000);
    
    $span->setStatus(SpanStatus::OK);
    $span->end();
}

processRequest($tracer2);
$tracer2->flush();

echo "\n";

// Example 7: Sampling
echo "7. Sampling Configuration\n";
echo str_repeat('-', 50) . "\n";

// 50% sampling rate
$sampledTracer = new Tracer(
    'sampled-service',
    new ConsoleExporter(true),
    null,
    null,
    ['sampling_rate' => 0.5]
);

echo "Sampling rate: 50%\n";
echo "Creating 10 traces (approximately 5 should be sampled):\n";

$sampledCount = 0;
for ($i = 0; $i < 10; $i++) {
    $span = $sampledTracer->startTrace("request-$i");
    if ($span->getContext()->isSampled()) {
        $sampledCount++;
    }
    $span->end();
}

echo "Sampled: $sampledCount / 10\n";

echo "\n";

// Cleanup
$tracer->shutdown();
$tracer2->shutdown();
$sampledTracer->shutdown();

echo "=== Examples Complete ===\n";
