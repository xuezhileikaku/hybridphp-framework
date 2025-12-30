<?php

declare(strict_types=1);

/**
 * HTTP/2 Server Push Usage Examples
 * 
 * This file demonstrates how to use HTTP/2 Server Push in HybridPHP.
 * Server Push allows the server to proactively send resources to clients
 * before they are requested, improving page load performance.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HybridPHP\Core\Server\Http2Server;
use HybridPHP\Core\Server\Http2\ServerPushManager;
use HybridPHP\Core\Server\Http2\PushPromise;
use HybridPHP\Core\Server\Http2\Http2Config;
use HybridPHP\Core\Middleware\ServerPushMiddleware;
use HybridPHP\Core\Routing\Router;
use HybridPHP\Core\Container;
use Amp\Http\Server\Response;

// =============================================================================
// Example 1: Basic Server Push Setup
// =============================================================================

echo "=== Example 1: Basic Server Push Setup ===\n\n";

$container = new Container();
$router = new Router();

// Create HTTP/2 server with push enabled
$server = new Http2Server($router, $container, [
    'host' => '0.0.0.0',
    'port' => 8443,
    'enable_http2' => true,
    'enable_server_push' => true,
    'cert_path' => 'storage/ssl/server.crt',
    'key_path' => 'storage/ssl/server.key',
]);

// Get the push manager
$pushManager = $server->getPushManager();

// Register critical resources for all pages
$pushManager->registerResource('/css/app.css', 'style', [
    'priority' => 32,
]);
$pushManager->registerResource('/js/app.js', 'script', [
    'priority' => 24,
]);

echo "Registered 2 resources for server push\n";
echo "Stats: " . json_encode($pushManager->getStats(), JSON_PRETTY_PRINT) . "\n\n";

// =============================================================================
// Example 2: Push Rules for Different Pages
// =============================================================================

echo "=== Example 2: Push Rules for Different Pages ===\n\n";

// Push specific resources for the homepage
$pushManager->addPushRule('/', [
    '/css/home.css' => 'style',
    '/js/home.js' => 'script',
    '/images/hero.webp' => [
        'type' => 'image',
        'priority' => 16,
    ],
]);

// Push admin-specific resources
$pushManager->addPushRule('/admin/*', [
    '/css/admin.css' => 'style',
    '/js/admin.js' => 'script',
]);

// Push dashboard resources
$pushManager->addPushRule('/dashboard', [
    '/css/dashboard.css' => 'style',
    '/js/charts.js' => 'script',
]);

echo "Added 3 push rules\n";
echo "Rules: " . json_encode($pushManager->getPushRules(), JSON_PRETTY_PRINT) . "\n\n";

// =============================================================================
// Example 3: Working with Push Promises
// =============================================================================

echo "=== Example 3: Working with Push Promises ===\n\n";

// Create a push promise manually
$promise = new PushPromise('/css/critical.css', 'style', [
    'authority' => 'example.com',
    'priority' => 48,
    'headers' => [
        'cache-control' => 'max-age=31536000',
    ],
]);

echo "Created push promise:\n";
echo "  Path: " . $promise->getPath() . "\n";
echo "  Type: " . $promise->getResourceType() . "\n";
echo "  Priority: " . $promise->getPriority() . "\n";
echo "  State: " . $promise->getState() . "\n";
echo "  Link Header: " . $promise->toLinkHeader() . "\n";
echo "  Pseudo Headers: " . json_encode($promise->getPseudoHeaders()) . "\n\n";

// Simulate push lifecycle
$promise->markSent(2); // Stream ID 2
echo "After markSent: State = " . $promise->getState() . ", Stream ID = " . $promise->getPromisedStreamId() . "\n";

$promise->markCompleted();
echo "After markCompleted: State = " . $promise->getState() . ", Duration = " . number_format($promise->getDuration() * 1000, 2) . "ms\n\n";

// =============================================================================
// Example 4: Using Server Push Middleware
// =============================================================================

echo "=== Example 4: Using Server Push Middleware ===\n\n";

// Create middleware with options
$middleware = new ServerPushMiddleware($pushManager, null, [
    'enabled' => true,
    'only_html' => true,       // Only push for HTML responses
    'track_cookies' => true,   // Track pushed resources via cookie
    'cookie_name' => '_pushed',
    'cookie_ttl' => 3600,      // 1 hour
]);

echo "Created ServerPushMiddleware\n";
echo "  Enabled: " . ($middleware->isEnabled() ? 'yes' : 'no') . "\n\n";

// =============================================================================
// Example 5: Font Resources with CORS
// =============================================================================

echo "=== Example 5: Font Resources with CORS ===\n\n";

// Register font resources (automatically get crossorigin=anonymous)
$pushManager->registerResource('/fonts/roboto.woff2', 'font');
$pushManager->registerResource('/fonts/icons.woff2', 'font', [
    'crossorigin' => 'anonymous',
    'priority' => 20,
]);

// Create Link header for font
$fontResource = [
    'path' => '/fonts/roboto.woff2',
    'type' => 'font',
];
$linkHeader = $pushManager->createLinkHeader($fontResource);
echo "Font Link Header: $linkHeader\n\n";

// =============================================================================
// Example 6: Cache-Aware Pushing
// =============================================================================

echo "=== Example 6: Cache-Aware Pushing ===\n\n";

// Mark a resource as pushed
$pushManager->markPushed('/css/app.css');

// Check if it was pushed recently
$wasPushed = $pushManager->wasPushed('/css/app.css', 60);
echo "Was /css/app.css pushed in last 60s? " . ($wasPushed ? 'yes' : 'no') . "\n";

$wasPushed = $pushManager->wasPushed('/css/other.css', 60);
echo "Was /css/other.css pushed in last 60s? " . ($wasPushed ? 'yes' : 'no') . "\n\n";

// =============================================================================
// Example 7: Resource Type Detection
// =============================================================================

echo "=== Example 7: Resource Type Detection ===\n\n";

$testPaths = [
    '/css/style.css',
    '/js/app.js',
    '/js/module.mjs',
    '/fonts/font.woff2',
    '/images/photo.webp',
    '/images/icon.svg',
    '/data/config.json',
    '/page.html',
];

foreach ($testPaths as $path) {
    $type = $pushManager->detectType($path);
    echo "  $path => $type\n";
}
echo "\n";

// =============================================================================
// Example 8: Creating Combined Link Headers
// =============================================================================

echo "=== Example 8: Creating Combined Link Headers ===\n\n";

$resources = [
    ['path' => '/css/app.css', 'type' => 'style'],
    ['path' => '/js/app.js', 'type' => 'script'],
    ['path' => '/fonts/main.woff2', 'type' => 'font'],
];

$combinedHeader = $pushManager->createCombinedLinkHeader($resources);
echo "Combined Link Header:\n$combinedHeader\n\n";

// =============================================================================
// Example 9: Statistics and Monitoring
// =============================================================================

echo "=== Example 9: Statistics and Monitoring ===\n\n";

// Record some push events
$pushManager->recordSuccessfulPush(15000); // 15KB
$pushManager->recordSuccessfulPush(8000);  // 8KB
$pushManager->recordCancelledPush();

$stats = $pushManager->getStats();
echo "Push Statistics:\n";
echo json_encode($stats, JSON_PRETTY_PRINT) . "\n\n";

$detailedStats = $pushManager->getDetailedStats();
echo "Detailed Statistics:\n";
echo json_encode($detailedStats, JSON_PRETTY_PRINT) . "\n\n";

// =============================================================================
// Example 10: Route Handler with Server Push
// =============================================================================

echo "=== Example 10: Route Handler with Server Push ===\n\n";

// Define a route that returns HTML with resources
$router->get('/', function($request, $params) use ($pushManager) {
    // The HTML response - resources will be auto-detected
    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="/css/app.css">
    <link rel="preload" href="/fonts/main.woff2" as="font" crossorigin>
    <script src="/js/app.js" defer></script>
</head>
<body>
    <img class="hero" src="/images/hero.webp" alt="Hero">
    <h1>Welcome to HybridPHP</h1>
</body>
</html>
HTML;

    return new Response(200, ['content-type' => 'text/html'], $html);
});

echo "Route defined with HTML containing pushable resources\n";
echo "Resources will be auto-detected: CSS, JS, fonts, and hero images\n\n";

// =============================================================================
// Example 11: Reset and Cleanup
// =============================================================================

echo "=== Example 11: Reset and Cleanup ===\n\n";

echo "Before reset:\n";
echo "  Registered resources: " . count($pushManager->getRegisteredResources()) . "\n";
echo "  Push rules: " . count($pushManager->getPushRules()) . "\n";

// Clear specific items
$pushManager->clearPushedPaths();
echo "Cleared pushed paths cache\n";

// Full reset
$pushManager->reset();
echo "After reset:\n";
echo "  Registered resources: " . count($pushManager->getRegisteredResources()) . "\n";
echo "  Push rules: " . count($pushManager->getPushRules()) . "\n\n";

echo "=== Server Push Examples Complete ===\n";
