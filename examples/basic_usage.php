<?php
/**
 * LaboFrame 基本使用示例
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Application;
use Core\Coroutine\Coroutine;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

// 1. 创建应用配置
$config = [
    'debug' => true,
    'servers' => [
        'http' => [
            'host' => '0.0.0.0',
            'port' => 8080,
            'worker_num' => 2,
        ],
    ],
    'routes' => [
        // 基本路由
        ['method' => 'GET', 'path' => '/', 'handler' => function() {
            return new Response(200, ['Content-Type' => 'text/plain'], 'Hello LaboFrame!');
        }],
        
        // 带参数的路由
        ['method' => 'GET', 'path' => '/hello/{name}', 'handler' => function(Request $request, array $params) {
            $name = $params[0] ?? 'World';
            return new Response(200, ['Content-Type' => 'application/json'], 
                json_encode(['message' => "Hello, {$name}!"])
            );
        }],
        
        // 异步操作示例
        ['method' => 'GET', 'path' => '/async', 'handler' => function() {
            return Coroutine::create(function() {
                // 模拟异步操作
                delay(0.1);
                return new Response(200, ['Content-Type' => 'application/json'], 
                    json_encode(['type' => 'async', 'time' => microtime(true)])
                );
            });
        }],
        
        // 并发请求示例
        ['method' => 'GET', 'path' => '/concurrent', 'handler' => function() {
            return Coroutine::create(function() {
                $start = microtime(true);
                
                // 并发执行多个任务
                $futures = [
                    Coroutine::create(fn() => delay(0.1)),
                    Coroutine::create(fn() => delay(0.2)),
                    Coroutine::create(fn() => delay(0.1)),
                ];
                
                Coroutine::all($futures)->await();
                
                $duration = microtime(true) - $start;
                return new Response(200, ['Content-Type' => 'application/json'], 
                    json_encode(['duration' => $duration, 'expected' => 0.2])
                );
            });
        }],
    ],
    'components' => [
        'logger' => [
            'class' => \Core\Logger\FileLogger::class,
            'file' => 'storage/logs/example.log',
            'level' => 'debug',
        ],
    ],
];

// 2. 创建应用实例
$app = new Application($config);

// 3. 注册事件监听
$app->getEventLoop()->on('app.start', function() {
    echo "Example server started at http://localhost:8080\n";
    echo "Try these endpoints:\n";
    echo "  GET / - Basic hello world\n";
    echo "  GET /hello/{name} - Route with parameter\n";
    echo "  GET /async - Async operation\n";
    echo "  GET /concurrent - Concurrent operations\n";
});

// 4. 启动应用
$app->run();
