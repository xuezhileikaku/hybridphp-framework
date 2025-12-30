<?php

/**
 * HybridPHP Framework Bootstrap
 * 
 * This file bootstraps the HybridPHP Framework application,
 * combining Yii2 usability + Workerman multi-process + AMPHP async.
 */

require __DIR__ . '/vendor/autoload.php';

use HybridPHP\Core\Application;
use HybridPHP\Core\Routing\Router;
use HybridPHP\Core\Routing\RouterFacade;
use HybridPHP\Core\Server\HybridHttpServer;

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Helper function for environment variables
if (!function_exists('env')) {
    function env($key, $default = null) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        
        if ($value === null) {
            return $default;
        }
        
        // Convert string booleans
        if (in_array(strtolower($value), ['true', 'false'])) {
            return strtolower($value) === 'true';
        }
        
        return $value;
    }
}

// 1. Load configuration
$config = require __DIR__ . '/config/main.php';

// 2. Create Application instance
$app = new Application(__DIR__);

// 3. Load configuration into application
$app->config->load($config);

// 4. Create and configure router
$routerConfig = [
    'cache' => env('ROUTE_CACHE', false),
    'cache_file' => __DIR__ . '/storage/cache/routes.cache'
];
$router = new Router($routerConfig);

// 5. Set router instance for facade
RouterFacade::setRouter($router);

// 6. Load routes
require __DIR__ . '/routes/web.php';

// 7. Create HTTP server with router
$httpServerConfig = [
    'host' => env('HTTP_HOST', '0.0.0.0'),
    'port' => env('HTTP_PORT', 8080),
    'worker_count' => env('HTTP_WORKERS', 4),
    'max_connections' => env('HTTP_MAX_CONNECTIONS', 1000),
    'max_request' => env('HTTP_MAX_REQUEST', 10000),
    'user' => env('HTTP_USER', ''),
    'group' => env('HTTP_GROUP', ''),
    'reusePort' => env('HTTP_REUSE_PORT', false),
];

$httpServer = new HybridHttpServer($router, $app->container, $httpServerConfig);

// 8. Add HTTP server to application
$app->serverManager->addServer($httpServer);

// 9. Add lifecycle hooks
$app->addLifecycleHook('beforeStart', function($app) use ($router) {
    echo "Initializing HybridPHP Framework...\n";
    echo "Router: " . count($router->getRoutes()) . " routes loaded\n";
});

$app->addLifecycleHook('afterStart', function($app) {
    echo "HybridPHP Framework is ready!\n";
    echo "Visit: http://" . env('HTTP_HOST', '0.0.0.0') . ":" . env('HTTP_PORT', 8080) . "\n";
    echo "Health check: http://" . env('HTTP_HOST', '0.0.0.0') . ":" . env('HTTP_PORT', 8080) . "/api/v1/health\n";
});

$app->addLifecycleHook('beforeShutdown', function($app) {
    echo "Shutting down HybridPHP Framework...\n";
});

// 10. Start the application
$app->run();
