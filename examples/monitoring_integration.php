<?php

declare(strict_types=1);

/**
 * Example: Integrating Performance Monitoring into HybridPHP Application
 * 
 * This example demonstrates how to integrate the performance monitoring
 * and alerting system into a HybridPHP application.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HybridPHP\Core\Application;
use HybridPHP\Core\Container;
use HybridPHP\Core\FileLogger;
use HybridPHP\Core\Monitoring\MonitoringServiceProvider;
use HybridPHP\Core\Monitoring\PerformanceMonitor;
use HybridPHP\Core\Monitoring\MonitoringDashboard;
use HybridPHP\Core\Middleware\PerformanceMonitoringMiddleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Psr\Log\LoggerInterface;

// Create application
$app = new Application(__DIR__ . '/..');

// Setup monitoring configuration
$monitoringConfig = [
    'enabled' => true,
    'metrics' => [
        'collection_interval' => 5,
        'max_metrics' => 5000,
    ],
    'alerts' => [
        'processing_interval' => 10,
        'cooldown_period' => 60,
    ],
    'performance' => [
        'monitoring_interval' => 3,
        'memory_threshold' => 0.85,
        'cpu_threshold' => 0.8,
        'coroutine_threshold' => 100,
    ],
    'dashboard' => [
        'auth_enabled' => true,
        'auth_token' => 'your-secret-token-here',
        'refresh_interval' => 3000,
    ],
    'notifications' => [
        'log' => ['enabled' => true],
        'webhook' => [
            'enabled' => false,
            'url' => 'https://your-webhook-url.com/alerts',
        ],
        'slack' => [
            'enabled' => false,
            'webhook_url' => 'https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK',
        ],
    ],
];

// Register monitoring services
$monitoringProvider = new MonitoringServiceProvider($app->container, $monitoringConfig);
$monitoringProvider->register();

// Setup logger
$app->container->singleton(LoggerInterface::class, function () {
    return new FileLogger(__DIR__ . '/../storage/logs/app.log');
});

// Boot monitoring services
$monitoringProvider->boot();

// Get monitoring services
$performanceMonitor = $app->container->get(PerformanceMonitor::class);
$dashboard = $app->container->get(MonitoringDashboard::class);

// Add monitoring middleware to all routes
$monitoringMiddleware = new PerformanceMonitoringMiddleware($performanceMonitor, [
    'track_requests' => true,
    'track_response_size' => true,
    'exclude_paths' => ['/monitoring', '/health'],
]);

// Example API routes with monitoring
$app->addRoute('GET', '/api/users', function (Request $request) use ($performanceMonitor) {
    // Simulate some work
    $startTime = microtime(true);
    
    // Record custom business metric
    $performanceMonitor->getMetricsCollector()->incrementCounter('api_calls', [
        'endpoint' => '/api/users',
        'version' => 'v1',
    ]);
    
    // Simulate database query
    usleep(rand(50000, 200000)); // 50-200ms
    
    // Record custom timing
    $performanceMonitor->getMetricsCollector()->recordTiming(
        'database_query',
        $startTime,
        ['table' => 'users', 'operation' => 'select']
    );
    
    $users = [
        ['id' => 1, 'name' => 'John Doe', 'email' => 'john@example.com'],
        ['id' => 2, 'name' => 'Jane Smith', 'email' => 'jane@example.com'],
    ];
    
    return new Response(Status::OK, [
        'content-type' => 'application/json',
    ], json_encode($users));
});

$app->addRoute('POST', '/api/users', function (Request $request) use ($performanceMonitor) {
    // Record business metric
    $performanceMonitor->getMetricsCollector()->incrementCounter('user_registrations', [
        'source' => 'api',
        'plan' => 'free',
    ]);
    
    // Simulate validation and processing
    usleep(rand(100000, 300000)); // 100-300ms
    
    // Record gauge metric
    $performanceMonitor->getMetricsCollector()->setGauge('active_users', rand(100, 1000));
    
    return new Response(Status::CREATED, [
        'content-type' => 'application/json',
    ], json_encode(['id' => 3, 'status' => 'created']));
});

// Health check endpoint
$app->addRoute('GET', '/health', function (Request $request) use ($performanceMonitor) {
    $report = $performanceMonitor->getPerformanceReport();
    
    $health = [
        'status' => 'healthy',
        'timestamp' => date('c'),
        'checks' => [
            'memory' => [
                'status' => ($report['system']['php_memory_usage_ratio'] ?? 0) < 0.9 ? 'ok' : 'warning',
                'usage' => $report['system']['php_memory_usage_ratio'] ?? 0,
            ],
            'alerts' => [
                'status' => empty($report['alerts']) ? 'ok' : 'warning',
                'count' => count($report['alerts']),
            ],
        ],
    ];
    
    $status = empty($report['alerts']) && 
              ($report['system']['php_memory_usage_ratio'] ?? 0) < 0.9 ? 
              Status::OK : Status::SERVICE_UNAVAILABLE;
    
    return new Response($status, [
        'content-type' => 'application/json',
    ], json_encode($health));
});

// Monitoring dashboard routes
$app->addRoute('GET', '/monitoring/{path:.*}', function (Request $request) use ($dashboard) {
    return $dashboard->handleRequest($request);
});

// Prometheus metrics endpoint
$app->addRoute('GET', '/metrics', function (Request $request) use ($performanceMonitor) {
    $metrics = $performanceMonitor->getPrometheusMetrics();
    
    return new Response(Status::OK, [
        'content-type' => 'text/plain; version=0.0.4; charset=utf-8',
        'cache-control' => 'no-cache',
    ], $metrics);
});

// Example of custom alert rules
$alertManager = $app->container->get(\HybridPHP\Core\Monitoring\AlertManager::class);

// Add custom business logic alert
$alertManager->addRule('low_user_activity', function () use ($performanceMonitor) {
    $metrics = $performanceMonitor->getMetricsCollector()->getMetrics();
    
    foreach ($metrics['gauges'] as $gauge) {
        if ($gauge['name'] === 'active_users' && $gauge['value'] < 50) {
            return [
                'current_users' => $gauge['value'],
                'threshold' => 50,
            ];
        }
    }
    
    return false;
}, ['severity' => 'warning']);

// Add high API error rate alert
$alertManager->addRule('high_api_error_rate', function () use ($performanceMonitor) {
    $metrics = $performanceMonitor->getMetricsCollector()->getMetrics();
    
    $totalRequests = 0;
    $errorRequests = 0;
    
    foreach ($metrics['counters'] as $counter) {
        if ($counter['name'] === 'http_requests_total') {
            $totalRequests += $counter['value'];
            
            if (isset($counter['labels']['status']) && 
                in_array($counter['labels']['status'], ['500', '502', '503', '504'])) {
                $errorRequests += $counter['value'];
            }
        }
    }
    
    if ($totalRequests > 10) {
        $errorRate = $errorRequests / $totalRequests;
        if ($errorRate > 0.1) { // 10% error rate
            return [
                'error_rate' => $errorRate,
                'threshold' => 0.1,
                'total_requests' => $totalRequests,
                'error_requests' => $errorRequests,
            ];
        }
    }
    
    return false;
}, ['severity' => 'critical']);

echo "🚀 HybridPHP Application with Performance Monitoring\n";
echo "=" . str_repeat("=", 55) . "\n\n";

echo "📊 Monitoring Features Enabled:\n";
echo "   ✓ Request/Response tracking\n";
echo "   ✓ Memory and CPU monitoring\n";
echo "   ✓ Custom business metrics\n";
echo "   ✓ Alert system with notifications\n";
echo "   ✓ Real-time dashboard\n";
echo "   ✓ Prometheus metrics export\n";
echo "   ✓ ELK-compatible JSON export\n\n";

echo "🌐 Available Endpoints:\n";
echo "   GET  /api/users          - List users (with monitoring)\n";
echo "   POST /api/users          - Create user (with business metrics)\n";
echo "   GET  /health             - Health check endpoint\n";
echo "   GET  /monitoring         - Real-time dashboard\n";
echo "   GET  /metrics            - Prometheus metrics\n\n";

echo "🔧 Dashboard Access:\n";
echo "   URL: http://localhost:8080/monitoring\n";
echo "   Auth: Bearer your-secret-token-here\n";
echo "   Or:  http://localhost:8080/monitoring?token=your-secret-token-here\n\n";

echo "📈 Prometheus Integration:\n";
echo "   Metrics URL: http://localhost:8080/metrics\n";
echo "   Scrape interval: 15s (recommended)\n\n";

echo "🚨 Alert Notifications:\n";
echo "   - Log file: storage/logs/app.log\n";
echo "   - Webhook: Configure in monitoring config\n";
echo "   - Slack: Configure webhook URL\n\n";

echo "💡 Custom Metrics Examples:\n";
echo "   - api_calls (counter): Track API usage\n";
echo "   - user_registrations (counter): Business KPI\n";
echo "   - active_users (gauge): Current user count\n";
echo "   - database_query (histogram): Query performance\n\n";

echo "⚠️  Alert Rules Configured:\n";
echo "   - High memory usage (>85%)\n";
echo "   - High CPU load (>80%)\n";
echo "   - Too many coroutines (>100)\n";
echo "   - Low user activity (<50 users)\n";
echo "   - High API error rate (>10%)\n\n";

echo "🔍 To test the monitoring:\n";
echo "   1. Start the application: php examples/monitoring_integration.php\n";
echo "   2. Make requests to /api/users\n";
echo "   3. Check dashboard at /monitoring\n";
echo "   4. View metrics at /metrics\n";
echo "   5. Monitor alerts in logs\n\n";

echo "📁 Log Files:\n";
echo "   - Application: storage/logs/app.log\n";
echo "   - Monitoring: storage/logs/monitoring.log\n\n";

// Start the application (this would normally be done by the server)
echo "✅ Monitoring system configured and ready!\n";
echo "   Start your HybridPHP server to begin monitoring.\n";