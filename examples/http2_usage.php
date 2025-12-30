<?php

declare(strict_types=1);

/**
 * HTTP/2 Server Usage Example
 * 
 * This example demonstrates how to use the HTTP/2 server with:
 * - TLS/SSL encryption
 * - Server Push
 * - Multiplexing
 * - HPACK header compression
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HybridPHP\Core\Server\Http2Server;
use HybridPHP\Core\Server\Http2\Http2Config;
use HybridPHP\Core\Server\Http2\ServerPushManager;
use HybridPHP\Core\Routing\Router;
use HybridPHP\Core\Container;

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

echo "=== HybridPHP HTTP/2 Server Example ===\n\n";

// Create container and router
$container = new Container();
$router = new Router();

// Define routes
$router->get('/', function($request, $params) {
    return [
        'message' => 'Welcome to HybridPHP HTTP/2 Server!',
        'protocol' => 'HTTP/2',
        'features' => [
            'Server Push',
            'Multiplexing',
            'HPACK Header Compression',
            'TLS 1.2/1.3',
        ],
    ];
});

$router->get('/api/status', function($request, $params) {
    return [
        'status' => 'healthy',
        'timestamp' => date('c'),
        'server' => 'HybridPHP HTTP/2',
    ];
});

// HTML page with resources to push
$router->get('/demo', function($request, $params) {
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>HTTP/2 Demo</title>
    <link rel="stylesheet" href="/css/app.css">
    <link rel="preload" href="/fonts/main.woff2" as="font" crossorigin>
</head>
<body>
    <h1>HTTP/2 Server Push Demo</h1>
    <p>This page demonstrates HTTP/2 Server Push.</p>
    <p>The CSS and font files are pushed before the browser requests them.</p>
    <script src="/js/app.js"></script>
</body>
</html>
HTML;
});

// Configure HTTP/2 server
$config = [
    'host' => '0.0.0.0',
    'port' => 8443,
    'enable_http2' => true,
    'enable_server_push' => true,
    
    // TLS configuration
    'cert_path' => __DIR__ . '/../storage/ssl/server.crt',
    'key_path' => __DIR__ . '/../storage/ssl/server.key',
    'min_tls_version' => 'TLSv1.2',
    'allow_self_signed' => true, // For development only
    
    // HTTP/2 settings
    'max_concurrent_streams' => 100,
    'initial_window_size' => 65535,
    'max_frame_size' => 16384,
    'header_table_size' => 4096,
    
    // Server Push rules
    'push_rules' => [
        '/demo' => [
            '/css/app.css' => 'style',
            '/js/app.js' => 'script',
            '/fonts/main.woff2' => [
                'type' => 'font',
                'crossorigin' => 'anonymous',
            ],
        ],
    ],
];

// Create HTTP/2 server
$server = new Http2Server($router, $container, $config);

// Register additional push resources
$pushManager = $server->getPushManager();
$pushManager->registerResource('/css/critical.css', 'style', ['priority' => 'high']);
$pushManager->registerResource('/js/vendor.js', 'script');

// Add push rule for all pages
$pushManager->addPushRule('/*', [
    '/css/common.css' => 'style',
]);

echo "HTTP/2 Server Configuration:\n";
echo "  Host: {$config['host']}\n";
echo "  Port: {$config['port']}\n";
echo "  HTTP/2: " . ($config['enable_http2'] ? 'Enabled' : 'Disabled') . "\n";
echo "  Server Push: " . ($config['enable_server_push'] ? 'Enabled' : 'Disabled') . "\n";
echo "  TLS: TLSv1.2 - TLSv1.3\n";
echo "\n";

echo "Available Routes:\n";
echo "  GET /       - JSON API response\n";
echo "  GET /api/status - Health check\n";
echo "  GET /demo   - HTML page with Server Push\n";
echo "\n";

echo "Server Push Resources:\n";
foreach ($pushManager->getRegisteredResources() as $path => $resource) {
    echo "  {$path} ({$resource['type']})\n";
}
echo "\n";

// Check SSL certificates
if (!file_exists($config['cert_path'])) {
    echo "⚠️  SSL certificate not found!\n";
    echo "Run: php scripts/generate-ssl-cert.php\n\n";
}

echo "Starting HTTP/2 server...\n";
echo "Access: https://localhost:8443/\n";
echo "\n";

// Start the server
try {
    $server->listen();
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    
    if (str_contains($e->getMessage(), 'certificate')) {
        echo "\nTo generate SSL certificates for development:\n";
        echo "  php scripts/generate-ssl-cert.php\n";
    }
}
