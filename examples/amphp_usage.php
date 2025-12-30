<?php
require_once 'vendor/autoload.php';

use Core\Application;
use Core\Server\AmphpHttpServer;

// amphp配置示例
$config = [
    'debug' => true,
    'servers' => [
        'http' => [
            'type' => 'amphp',
            'host' => '0.0.0.0',
            'port' => 8080,
            'processes' => 1, // amphp使用协程，不需要多进程
        ],
    ],
    
    'components' => [
        'logger' => [
            'class' => \Core\Logger\FileLogger::class,
            'file' => 'storage/logs/amphp.log',
            'level' => 'debug',
        ],
    ],
    
    'routes' => [
        ['method' => 'GET', 'path' => '/', 'handler' => function() {
            return ['message' => 'LaboFrame with Amphp', 'version' => '2.2.0'];
        }],
        ['method' => 'GET', 'path' => '/async', 'handler' => function() {
            return async(function() {
                delay(1); // 模拟异步操作
                return ['message' => 'Async response'];
            })->await();
        }],
        ['method' => 'GET', 'path' => '/users/{id}', 'handler' => function($request, $id) {
            return ['user_id' => $id, 'method' => $request->getMethod()];
        }],
    ],
];

// 创建应用
$app = new Application($config);

// 注册事件监听
$app->getEventLoop()->on('app.start', function() {
    echo "🚀 LaboFrame with Amphp Started!\n";
    echo "📊 Endpoints:\n";
    echo "  HTTP: http://localhost:8080\n";
    echo "  Async: http://localhost:8080/async\n";
    echo "  Users: http://localhost:8080/users/123\n";
});

// 启动
$app->run();
