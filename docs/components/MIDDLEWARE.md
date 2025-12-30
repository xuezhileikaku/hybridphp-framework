# 中间件系统

HybridPHP 提供 PSR-15 兼容的异步中间件系统，支持洋葱模型、优先级控制和灵活的组织方式。

## 核心特性

- **PSR-15 兼容**: 完全兼容 PSR-15 标准
- **异步支持**: 协程环境下无阻塞运行
- **洋葱模型**: 请求/响应双向处理
- **优先级系统**: 控制中间件执行顺序
- **内置中间件**: 认证、CORS、限流、日志

## 架构

```
Request → CORS → Auth → RateLimit → Controller
                                         ↓
Response ← CORS ← Auth ← RateLimit ← Response
```

## 快速开始

### 基础配置

```php
use HybridPHP\Core\MiddlewareManager;
use HybridPHP\Core\MiddlewarePipeline;

$manager = new MiddlewareManager();

// 全局中间件
$manager->addGlobal('cors', 100);  // 高优先级
$manager->addGlobal('log', 90);

// 分组中间件
$manager->addToGroup('api', 'throttle', 80);
$manager->addToGroup('api', 'auth', 70);

// 路由中间件
$manager->addToRoute('user.profile', 'auth', 60);
```

### 创建管道

```php
// 公开路由管道
$publicPipeline = $manager->createPipeline($handler);

// API 路由管道
$apiPipeline = $manager->createPipeline($handler, ['api']);

// 特定路由管道
$protectedPipeline = $manager->createPipeline($handler, ['api'], 'user.profile');

// 处理请求
$response = $pipeline->handle($request);
```

## 自定义中间件

### 基础中间件

```php
use HybridPHP\Core\Middleware\AbstractMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

class CustomMiddleware extends AbstractMiddleware
{
    protected function before(ServerRequestInterface $request): ServerRequestInterface
    {
        // 请求前处理
        return $request->withAttribute('custom_data', 'value');
    }
    
    protected function after(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        // 响应后处理
        return $response->withHeader('X-Custom-Header', 'processed');
    }
}
```

### 完整控制

```php
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TimingMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $startTime = microtime(true);
        
        // 调用下一个中间件
        $response = $handler->handle($request);
        
        $duration = microtime(true) - $startTime;
        
        return $response->withHeader('X-Response-Time', (string)$duration);
    }
}
```

## 内置中间件

### CORS 中间件

```php
use HybridPHP\Core\Middleware\CorsMiddleware;

$corsMiddleware = new CorsMiddleware([
    'allowed_origins' => ['https://example.com', 'https://app.example.com'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
    'allowed_headers' => ['Content-Type', 'Authorization'],
    'credentials' => true,
    'max_age' => 86400
]);
```

### 认证中间件

```php
use HybridPHP\Core\Middleware\AuthMiddleware;

$authMiddleware = new AuthMiddleware([
    'auth_type' => 'jwt',
    'jwt_secret' => 'your-secret-key',
    'excluded_paths' => ['/login', '/register', '/health'],
    'unauthorized_message' => 'Access denied'
]);
```

### 限流中间件

```php
use HybridPHP\Core\Middleware\RateLimitMiddleware;

$rateLimitMiddleware = new RateLimitMiddleware(null, [
    'algorithm' => 'token_bucket',
    'max_requests' => 100,
    'window_seconds' => 3600,
    'burst_size' => 10,
    'refill_rate' => 1
]);
```

### 日志中间件

```php
use HybridPHP\Core\Middleware\LoggingMiddleware;

$loggingMiddleware = new LoggingMiddleware($logger, [
    'log_requests' => true,
    'log_responses' => true,
    'log_request_body' => false,
    'excluded_paths' => ['/health', '/metrics'],
    'max_body_size' => 1024
]);
```

## 中间件别名

```php
// 内置别名
$manager->addGlobal('cors');     // CorsMiddleware
$manager->addGlobal('auth');     // AuthMiddleware
$manager->addGlobal('log');      // LoggingMiddleware
$manager->addGlobal('throttle'); // RateLimitMiddleware

// 自定义别名
$manager->alias('custom_auth', CustomAuthMiddleware::class);
$manager->addGlobal('custom_auth');
```

## 优先级系统

优先级值越高，越先执行：

```php
$manager->addGlobal('cors', 100);    // 最先执行
$manager->addGlobal('auth', 75);     // 第二
$manager->addGlobal('log', 50);      // 第三
```

## 路由集成

```php
// 路由级中间件
Route::get('/api/users', UserController::class)
    ->middleware(['auth', 'throttle']);

// 路由组中间件
Route::group(['prefix' => 'api', 'middleware' => ['cors', 'auth']], function() {
    Route::get('/users', UserController::class);
    Route::post('/users', UserController::class);
});
```

## 错误处理

```php
class ErrorHandlingMiddleware extends AbstractMiddleware
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        try {
            return parent::process($request, $handler);
        } catch (\Throwable $e) {
            $this->logger->error('Middleware error', ['exception' => $e]);
            return new Response(500, [], 'Internal Server Error');
        }
    }
}
```

## 异步兼容

所有中间件在 AMPHP 协程环境下无缝运行：

```php
class AsyncMiddleware extends AbstractMiddleware
{
    protected function before(ServerRequestInterface $request): ServerRequestInterface
    {
        // 异步操作自动处理
        $data = $this->asyncService->fetchData();
        return $request->withAttribute('async_data', $data);
    }
}
```

## 最佳实践

1. **单一职责**: 每个中间件只做一件事
2. **合理优先级**: 安全相关中间件优先级最高
3. **错误处理**: 提供降级响应
4. **避免阻塞**: 使用异步操作
5. **充分测试**: 单元测试和集成测试

## 下一步

- [路由系统](./ROUTING.md) - 路由详解
- [认证授权](./AUTH.md) - 认证中间件
