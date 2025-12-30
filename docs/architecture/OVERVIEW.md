# HybridPHP 架构设计

## 设计理念

HybridPHP 的核心设计理念是**融合三大优秀框架的精华**，打造一个既高性能又易用的现代 PHP 框架：

| 来源框架 | 继承特性 | 优势 |
|---------|---------|------|
| **Yii2** | 易用性、约定优于配置 | 降低学习成本，提高开发效率 |
| **Workerman** | 多进程架构、内存常驻 | 充分利用多核CPU，避免重复初始化 |
| **AMPHP** | 协程驱动、非阻塞I/O | 高并发处理，单进程处理数千连接 |

## 系统架构

```
┌─────────────────────────────────────────────────────────────┐
│                    Application Layer                        │
│         Controllers, Services, Middleware, Commands        │
├─────────────────────────────────────────────────────────────┤
│                     Service Layer                          │
│      Business Logic, ORM, Cache, Auth, Queue, etc.        │
├─────────────────────────────────────────────────────────────┤
│                      Core Layer                            │
│    Router, Container, Event, Config, Exception, PSR       │
├─────────────────────────────────────────────────────────────┤
│                 Infrastructure Layer                       │
│     AMPHP, Workerman, MySQL, Redis, File Storage          │
└─────────────────────────────────────────────────────────────┘
```

### 分层说明

#### 1. 应用层 (Application Layer)
- **Controllers**: 处理 HTTP 请求，调用服务层
- **Middleware**: 请求/响应拦截处理
- **Commands**: CLI 命令行工具

#### 2. 服务层 (Service Layer)
- **ORM**: 异步数据库操作
- **Cache**: 多级缓存系统
- **Auth**: 认证授权服务
- **Queue**: 异步任务队列

#### 3. 核心层 (Core Layer)
- **Container**: PSR-11 依赖注入容器
- **Router**: 高性能路由匹配
- **Event**: 事件驱动系统
- **Config**: 配置管理

#### 4. 基础设施层 (Infrastructure Layer)
- **AMPHP**: 协程和异步 I/O
- **Workerman**: 多进程管理
- **数据库/缓存**: MySQL、Redis 等

## 核心组件

### 依赖注入容器

```php
use HybridPHP\Core\Container;

$container = new Container();

// 绑定服务
$container->bind('logger', FileLogger::class);
$container->singleton('database', DatabaseManager::class);

// 获取服务
$logger = $container->get('logger');
```

### 路由系统

```php
use HybridPHP\Core\Routing\RouterFacade as Router;

// 基础路由
Router::get('/', [HomeController::class, 'index']);
Router::post('/users', [UserController::class, 'store']);

// 路由组
Router::group(['prefix' => 'api/v1', 'middleware' => ['auth']], function() {
    Router::resource('posts', PostController::class);
});
```

### 中间件管道

```php
// 洋葱模型中间件
Request → CORS → Auth → RateLimit → Controller
                                         ↓
Response ← CORS ← Auth ← RateLimit ← Response
```

### 事件系统

```php
// 监听事件
$app->event->on('user.created', function($user) {
    // 发送欢迎邮件
});

// 触发事件
$app->event->emit('user.created', [$user]);
```

## 请求生命周期

```
┌──────────────┐
│   HTTP 请求  │
└──────┬───────┘
       ↓
┌──────────────┐
│  Bootstrap   │ ← 加载配置、注册服务
└──────┬───────┘
       ↓
┌──────────────┐
│   Router     │ ← 路由匹配
└──────┬───────┘
       ↓
┌──────────────┐
│  Middleware  │ ← 中间件处理
└──────┬───────┘
       ↓
┌──────────────┐
│  Controller  │ ← 业务处理
└──────┬───────┘
       ↓
┌──────────────┐
│   Response   │ ← 响应输出
└──────────────┘
```

## 异步处理模型

HybridPHP 基于 AMPHP 实现协程式异步处理：

```php
// 异步数据库查询
$users = User::query()
    ->where('status', 'active')
    ->get()->await();

// 并发请求
$results = async(function() {
    $user = User::find(1)->await();
    $posts = Post::where('user_id', 1)->get()->await();
    return [$user, $posts];
})->await();
```

### 协程优势

1. **非阻塞 I/O**: 数据库、网络请求不阻塞主线程
2. **高并发**: 单进程可处理数千并发连接
3. **资源高效**: 协程切换开销远小于线程
4. **代码简洁**: 同步风格编写异步代码

## 多进程架构

```
┌─────────────────────────────────────────┐
│              Master Process             │
│  • 进程管理  • 信号处理  • 热重载       │
└─────────────────┬───────────────────────┘
                  │
    ┌─────────────┼─────────────┐
    ↓             ↓             ↓
┌─────────┐  ┌─────────┐  ┌─────────┐
│ Worker1 │  │ Worker2 │  │ Worker3 │
│ 协程池  │  │ 协程池  │  │ 协程池  │
└─────────┘  └─────────┘  └─────────┘
```

### 进程模型特点

- **Master 进程**: 管理 Worker 进程，处理信号
- **Worker 进程**: 处理实际请求，每个 Worker 独立运行
- **协程池**: 每个 Worker 内部使用协程处理并发

## 连接池管理

```php
// 数据库连接池
$pool = new ConnectionPool([
    'min_connections' => 5,
    'max_connections' => 20,
    'idle_timeout' => 60,
]);

// 自动获取和释放连接
$result = $pool->query('SELECT * FROM users')->await();
```

## 配置管理

支持点语法访问配置：

```php
// config/database.php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host' => env('DB_HOST', 'localhost'),
            'database' => env('DB_DATABASE', 'hybridphp'),
        ],
    ],
];

// 使用配置
$host = $config->get('database.connections.mysql.host');
```

## 扩展机制

### 服务提供者

```php
class CustomServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton('custom', CustomService::class);
    }

    public function boot(Container $container): void
    {
        // 启动时执行
    }
}
```

### 中间件扩展

```php
class CustomMiddleware extends AbstractMiddleware
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // 前置处理
        $response = $handler->handle($request);
        // 后置处理
        return $response;
    }
}
```

## 设计原则

1. **PSR 标准兼容**: PSR-3, PSR-4, PSR-7, PSR-11, PSR-15
2. **约定优于配置**: 减少配置，提高开发效率
3. **组件化设计**: 各组件独立，可按需使用
4. **异步优先**: 所有 I/O 操作默认异步
5. **安全第一**: 内置多层安全防护

## 下一步

- [依赖注入容器](./CONTAINER.md) - 深入了解容器实现
- [生命周期](./LIFECYCLE.md) - 应用生命周期详解
- [服务提供者](./SERVICE_PROVIDER.md) - 服务注册机制
