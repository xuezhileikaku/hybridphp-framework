<?php
require_once 'vendor/autoload.php';

use Core\Application;
use Core\Routing\Router;

// 高级配置示例
$config = [
    'debug' => true,
    'servers' => [
        'http' => [
            'type' => 'http',
            'host' => '0.0.0.0',
            'port' => 8080,
            'processes' => 2,
        ],
        'websocket' => [
            'type' => 'websocket',
            'host' => '0.0.0.0',
            'port' => 9090,
            'processes' => 1,
        ],
    ],
    
    'components' => [
        'logger' => [
            'class' => \Core\Logger\FileLogger::class,
            'file' => 'storage/logs/advanced.log',
            'level' => 'debug',
        ],
        'router' => [
            'class' => \Core\Routing\Router::class,
        ],
    ],
    
    'routes' => [
        ['method' => 'GET', 'path' => '/', 'handler' => function() {
            return ['message' => 'LaboFrame 2.0 Advanced Demo', 'version' => '2.0.0'];
        }],
        ['method' => 'GET', 'path' => '/status', 'handler' => function() use (&$app) {
            return $app->status();
        }],
        ['method' => 'GET', 'path' => '/config/{key}', 'handler' => function($request, $key) use (&$app) {
            return ['config' => $app->getConfig()->get($key)];
        }],
    ],
    
    'websocket' => [
        'handlers' => [
            'echo' => function($connection, $message) {
                return ['type' => 'echo', 'data' => $message['data']];
            },
            'broadcast' => function($connection, $message) use (&$app) {
                $data = ['type' => 'broadcast', 'from' => $connection->id, 'data' => $message['data']];
                $app->getServerManager()->getServers()['websocket']->broadcast($data, $connection);
                return ['type' => 'sent', 'message' => 'Broadcast sent'];
            },
        ],
    ],
];

// 创建应用
$app = new Application($config);

// 注册事件监听
$app->getEventLoop()->on('app.start', function() {
    echo "🚀 LaboFrame 2.0 Advanced Demo Started!\n";
    echo "📊 Status: http://localhost:8080/status\n";
    echo "🔧 Config: http://localhost:8080/config/servers\n";
    echo "💬 WebSocket: ws://localhost:9090\n";
});

// 启动
$app->run();
