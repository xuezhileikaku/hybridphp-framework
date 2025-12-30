<?php
return [
    'appName' => 'HybridPHP',
    'debug' => env('APP_DEBUG', true),
    'timezone' => env('APP_TIMEZONE', 'Asia/Shanghai'),
    'env' => env('APP_ENV', 'development'),
    
    // 服务配置（供 ServerManager 加载）
    'servers' => [
        [
            'class' => \HybridPHP\Core\Server\HttpServer::class,
            'params' => [env('HTTP_HOST', '0.0.0.0'), env('HTTP_PORT', 8080)],
        ],
        [
            'class' => \HybridPHP\Core\Server\WebsocketServer::class,
            'params' => [env('WS_HOST', '0.0.0.0'), env('WS_PORT', 2346)],
        ],
    ],
     // 路由配置示例
    'routes' => [
        [
            'method' => 'GET',
            'path' => '/',
            'handler' => [\App\Controllers\HomeController::class, 'index'],
        ],
        [
            'method' => 'GET',
            'path' => '/hello',
            'handler' => [\App\Controllers\HomeController::class, 'hello'],
        ],
    ],
    
    'websocket' => [
        'handlers' => [
            'chat' => 'App\WebSocket\ChatHandler@handleMessage',
            'ping' => function($connection, $message) {
                return ['type' => 'pong', 'data' => $message['data'] ?? []];
            },
        ],
    ],
    
    'components' => [
        'logger' => [
            'class' => \HybridPHP\Core\FileLogger::class,
            'file' => env('LOG_FILE', 'storage/logs/app.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],
        'db' => [
            'class' => \HybridPHP\Core\Database\AsyncMySQLConnection::class,
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'hybridphp'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'pool' => [
                'min' => env('DB_POOL_MIN', 5), 
                'max' => env('DB_POOL_MAX', 50)
            ],
        ],
        'cache' => [
            'class' => \HybridPHP\Core\Cache\AsyncRedisCache::class,
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_DATABASE', 0),
            'password' => env('REDIS_PASSWORD', null),
        ],
        'router' => [
            'class' => \HybridPHP\Core\Routing\Router::class,
            'routes' => [], // 将由应用填充
        ],
    ],
    
    'middlewares' => [
        'cors' => \App\Middleware\CorsMiddleware::class,
        'auth' => \App\Middleware\AuthMiddleware::class,
        'throttle' => \App\Middleware\ThrottleMiddleware::class,
    ],
];