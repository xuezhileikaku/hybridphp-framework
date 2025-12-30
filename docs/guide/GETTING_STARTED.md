# 新手入门指南

> 从零开始，循序渐进掌握 HybridPHP 高性能 PHP 框架

---

## 目录

1. [框架简介](#1-框架简介)
2. [快速开始](#2-快速开始)
3. [核心概念](#3-核心概念)
4. [基础开发](#4-基础开发)
5. [进阶功能](#5-进阶功能)
6. [生产部署](#6-生产部署)

---

## 1. 框架简介

### 什么是 HybridPHP？

HybridPHP 是一个创新的高性能 PHP 框架，融合了三大优秀框架的精华：

| 来源框架 | 继承特性 | 优势 |
|---------|---------|------|
| **Yii2** | 易用性、约定优于配置 | 降低学习成本，提高开发效率 |
| **Workerman** | 多进程架构、内存常驻 | 充分利用多核CPU，避免重复初始化 |
| **AMPHP** | 协程驱动、非阻塞I/O | 高并发处理，单进程处理数千连接 |

### 核心特性

```
┌─────────────────────────────────────────────────────────────┐
│                    🚀 高性能架构                            │
│  • 异步非阻塞I/O    • 多进程架构    • 智能连接池           │
├─────────────────────────────────────────────────────────────┤
│                    🛠️ 开发友好                              │
│  • Yii2风格API      • 强大CLI工具   • 完善调试工具         │
├─────────────────────────────────────────────────────────────┤
│                    🔒 企业级安全                            │
│  • 多层安全防护     • 数据加密      • RBAC权限系统         │
├─────────────────────────────────────────────────────────────┤
│                    ☁️ 云原生支持                            │
│  • Docker/K8s部署   • CI/CD集成     • 蓝绿部署             │
└─────────────────────────────────────────────────────────────┘
```

### 性能基准

在 4核8GB 服务器上的测试结果：
- **QPS**: 15,000+
- **平均响应时间**: 50ms
- **内存使用**: 256MB
- **错误率**: 0%

---

## 2. 快速开始

### 环境要求

| 组件 | 最低版本 | 推荐版本 |
|------|---------|---------|
| PHP | 8.1 | 8.2+ |
| MySQL | 5.7 | 8.0+ |
| Redis | 5.0 | 7.0+ |
| 内存 | 512MB | 2GB+ |

**必需 PHP 扩展**：
```bash
# 检查扩展
php -m | grep -E "(json|openssl|pcntl|posix|sockets|pdo_mysql)"
```

### 安装步骤

```bash
# 1. 克隆项目
git clone https://github.com/hybridphp/framework.git my-app
cd my-app

# 2. 安装依赖
composer install --optimize-autoloader

# 3. 配置环境
cp .env.example .env
# 编辑 .env 文件，设置数据库等配置

# 4. 初始化数据库
php bin/hybrid migrate
php bin/hybrid seed

# 5. 启动应用
php bootstrap.php
```

### 验证安装

```bash
# 访问首页
curl http://localhost:8080

# 健康检查
curl http://localhost:8080/api/v1/health
```

### 目录结构

```
hybridphp/
├── app/                    # 📁 应用代码
│   ├── Controllers/        #    控制器
│   ├── Models/             #    数据模型
│   ├── Middleware/         #    中间件
│   └── Entities/           #    实体类
├── core/                   # 📁 框架核心（勿修改）
│   ├── Http/               #    HTTP组件
│   ├── Database/           #    数据库组件
│   ├── Cache/              #    缓存组件
│   ├── Security/           #    安全组件
│   └── Routing/            #    路由组件
├── config/                 # 📁 配置文件
│   ├── main.php            #    主配置
│   ├── database.php        #    数据库配置
│   └── cache.php           #    缓存配置
├── database/               # 📁 数据库相关
│   ├── migrations/         #    数据库迁移
│   └── seeds/              #    数据填充
├── routes/                 # 📁 路由定义
│   └── web.php             #    Web路由
├── storage/                # 📁 存储目录
│   ├── logs/               #    日志文件
│   └── cache/              #    缓存文件
├── tests/                  # 📁 测试代码
├── bootstrap.php           # 🚀 启动文件
└── .env                    # ⚙️ 环境配置
```

---

## 3. 核心概念

### 应用生命周期

```
启动流程:
┌──────────────┐    ┌──────────────┐    ┌──────────────┐
│  加载配置    │───▶│  注册服务    │───▶│  启动服务器  │
└──────────────┘    └──────────────┘    └──────────────┘
                           │
                           ▼
                    ┌──────────────┐
                    │  处理请求    │◀─── 循环处理
                    └──────────────┘
```

### 依赖注入容器

```php
// 绑定服务
$container->bind('logger', FileLogger::class);

// 单例绑定
$container->singleton('database', DatabaseManager::class);

// 获取服务
$logger = $container->get('logger');
```

### 配置管理

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

---

## 4. 基础开发

### 路由系统

```php
// routes/web.php
use HybridPHP\Core\Routing\RouterFacade as Router;

// GET 请求
Router::get('/', [HomeController::class, 'index']);

// POST 请求
Router::post('/users', [UserController::class, 'store']);

// 带参数路由
Router::get('/user/{id}', [UserController::class, 'show']);

// 路由组
Router::group(['prefix' => 'api/v1', 'middleware' => ['auth']], function() {
    Router::get('/users', [UserController::class, 'index']);
    Router::resource('posts', PostController::class);
});
```

### 控制器

```php
// app/Controllers/UserController.php
namespace App\Controllers;

use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;

class UserController
{
    public function index(Request $request): Response
    {
        $users = User::query()->where('status', 'active')->get()->await();
        
        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    }
    
    public function store(Request $request): Response
    {
        $data = $request->getParsedBody();
        
        // 验证数据
        if (!$request->validate([
            'name' => 'required|string|min:2',
            'email' => 'required|email',
        ])) {
            return response()->json(['errors' => $request->getErrors()], 422);
        }
        
        $user = User::create($data)->await();
        
        return response()->json(['data' => $user], 201);
    }
}
```

### 数据库操作

```php
// 基础查询
$users = User::query()->where('status', 'active')->get()->await();

// 复杂查询
$users = User::query()
    ->where('age', '>', 18)
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get()->await();

// 关联查询
$users = User::query()
    ->with(['posts', 'profile'])
    ->get()->await();

// CRUD 操作
$user = User::create(['name' => 'John'])->await();
$user = User::find(1)->await();
$user->name = 'Jane';
$user->save()->await();
$user->delete()->await();
```

### 中间件

```php
// app/Middleware/CustomMiddleware.php
namespace App\Middleware;

use HybridPHP\Core\Middleware\AbstractMiddleware;

class CustomMiddleware extends AbstractMiddleware
{
    public function process($request, $handler): ResponseInterface
    {
        // 请求前处理
        $startTime = microtime(true);
        
        // 调用下一个中间件
        $response = $handler->handle($request);
        
        // 响应后处理
        $duration = microtime(true) - $startTime;
        
        return $response->withHeader('X-Response-Time', (string)$duration);
    }
}
```

---

## 5. 进阶功能

### 认证系统

```php
use function HybridPHP\Core\Auth\auth;

// 登录
$user = auth()->guard('jwt')->attempt([
    'username' => 'john@example.com',
    'password' => 'password123'
])->await();

// 生成 Token
$token = auth()->guard('jwt')->login($user)->await();
```

### 缓存系统

```php
// 基础操作
$cache->set('user:123', $userData, 3600)->await();
$user = $cache->get('user:123')->await();

// 记住模式
$user = $cache->remember('user:123', function() {
    return User::find(123);
}, 3600)->await();
```

### 安全系统

```php
use HybridPHP\Core\Security\EncryptionService;

$encryption = new EncryptionService($key);

// 加密
$encrypted = $encryption->encrypt('sensitive data')->await();

// 解密
$decrypted = $encryption->decrypt($encrypted)->await();
```

---

## 6. 生产部署

### 环境配置

```env
# .env.production
APP_ENV=production
APP_DEBUG=false

DB_HOST=your-db-host
DB_DATABASE=your-db-name

REDIS_HOST=your-redis-host

HTTP_HOST=0.0.0.0
HTTP_PORT=8080
HTTP_WORKERS=8
```

### Docker 部署

```bash
# 构建镜像
docker build -t hybridphp-app .

# 运行容器
docker-compose up -d
```

### Supervisor 配置

```ini
[program:hybridphp]
command=/usr/bin/php /var/www/hybridphp/bootstrap.php
directory=/var/www/hybridphp
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/hybridphp.log
```

---

## CLI 命令速查

```bash
# 项目管理
php bin/hybrid serve                # 启动开发服务器

# 代码生成
php bin/hybrid make:controller User # 生成控制器
php bin/hybrid make:model Post      # 生成模型
php bin/hybrid make:middleware Auth # 生成中间件

# 数据库
php bin/hybrid migrate              # 运行迁移
php bin/hybrid migrate:rollback     # 回滚迁移
php bin/hybrid seed                 # 数据填充

# 缓存
php bin/hybrid cache:clear          # 清除缓存

# 监控
php bin/hybrid health:check         # 健康检查
```

---

## 下一步

- [架构设计](../architecture/OVERVIEW.md) - 深入了解框架架构
- [路由系统](../components/ROUTING.md) - 路由详细文档
- [数据库 ORM](../components/DATABASE.md) - 数据库操作详解
- [WebSocket](../advanced/WEBSOCKET.md) - 实时通信
- [IM 系统实战](../applications/IM_SYSTEM.md) - 高并发应用

---

**Happy Coding with HybridPHP! 🚀**
