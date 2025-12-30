# HybridPHP Framework 新手入门指南

> 从零开始，循序渐进掌握 HybridPHP 高性能 PHP 框架

---

## 目录

1. [框架简介](#1-框架简介)
2. [快速开始](#2-快速开始)
3. [核心概念](#3-核心概念)
4. [基础开发](#4-基础开发)
5. [进阶功能](#5-进阶功能)
6. [生产部署](#6-生产部署)
7. [最佳实践](#7-最佳实践)
8. [常见问题](#8-常见问题)

---

## 1. 框架简介

### 1.1 什么是 HybridPHP？

HybridPHP 是一个创新的高性能 PHP 框架，融合了三大优秀框架的精华：

| 来源框架 | 继承特性 | 优势 |
|---------|---------|------|
| **Yii2** | 易用性、约定优于配置 | 降低学习成本，提高开发效率 |
| **Workerman** | 多进程架构、内存常驻 | 充分利用多核CPU，避免重复初始化 |
| **AMPHP** | 协程驱动、非阻塞I/O | 高并发处理，单进程处理数千连接 |

### 1.2 核心特性一览

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

### 1.3 性能基准

在 4核8GB 服务器上的测试结果：
- **QPS**: 15,000+
- **平均响应时间**: 50ms
- **内存使用**: 256MB
- **错误率**: 0%

---

## 2. 快速开始

### 2.1 环境要求

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

### 2.2 安装步骤

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

### 2.3 验证安装

```bash
# 访问首页
curl http://localhost:8080

# 健康检查
curl http://localhost:8080/api/v1/health
```

### 2.4 目录结构

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

### 3.1 应用生命周期

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

### 3.2 依赖注入容器

HybridPHP 使用 PSR-11 兼容的依赖注入容器：

```php
// 绑定服务
$container->bind('logger', FileLogger::class);

// 单例绑定
$container->singleton('database', DatabaseManager::class);

// 获取服务
$logger = $container->get('logger');

// 检查服务
if ($container->has('logger')) {
    // 使用服务
}
```

### 3.3 配置管理

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
$default = $config->get('database.default'); // 'mysql'
```

### 3.4 事件系统

```php
// 监听事件
$app->event->on('user.created', function($user) {
    // 发送欢迎邮件
});

// 触发事件
$app->event->emit('user.created', [$user]);
```

---

## 4. 基础开发

### 4.1 路由系统

#### 基础路由

```php
// routes/web.php
use HybridPHP\Core\Routing\RouterFacade as Router;

// GET 请求
Router::get('/', [HomeController::class, 'index']);

// POST 请求
Router::post('/users', [UserController::class, 'store']);

// 带参数路由
Router::get('/user/{id}', [UserController::class, 'show']);

// 可选参数
Router::get('/posts/{category?}', [PostController::class, 'index']);
```

#### 路由组

```php
// API 路由组
Router::group(['prefix' => 'api/v1', 'middleware' => ['auth']], function() {
    Router::get('/users', [UserController::class, 'index']);
    Router::post('/users', [UserController::class, 'store']);
    Router::resource('posts', PostController::class);
});
```

### 4.2 控制器

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
    
    public function show(Request $request, array $params): Response
    {
        $user = User::find($params['id'])->await();
        
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }
        
        return response()->json(['data' => $user]);
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

### 4.3 请求处理

```php
// 获取请求数据
$data = $request->getParsedBody();      // POST 数据
$query = $request->getQueryParams();    // GET 参数
$name = $request->get('name');          // 自动从 GET/POST 获取

// 检查请求类型
$request->isPost();    // 是否 POST
$request->isAjax();    // 是否 AJAX
$request->isJson();    // 是否 JSON

// 获取客户端信息
$ip = $request->getClientIp();
$ua = $request->getUserAgent();

// 文件上传
$files = $request->getUploadedFiles();
if (isset($files['avatar'])) {
    $files['avatar']->save('/uploads', 'avatar.jpg');
}
```

### 4.4 响应处理

```php
use HybridPHP\Core\Http\ResponseFactory;

// JSON 响应
return ResponseFactory::json(['message' => 'Success']);

// 成功响应
return ResponseFactory::success($data, 'Operation successful');

// 错误响应
return ResponseFactory::error('Something went wrong', 400);

// 验证错误
return ResponseFactory::validationError($errors);

// 重定向
return ResponseFactory::redirect('/dashboard');

// 文件下载
return ResponseFactory::download('/path/to/file.pdf', 'document.pdf');
```

### 4.5 中间件

#### 使用内置中间件

```php
// 全局中间件
$app->middleware([
    \HybridPHP\Core\Middleware\CorsMiddleware::class,
    \HybridPHP\Core\Middleware\LoggingMiddleware::class,
]);

// 路由中间件
Router::get('/admin', [AdminController::class, 'index'])
    ->middleware(['auth', 'admin']);
```

#### 创建自定义中间件

```php
// app/Middleware/CustomMiddleware.php
namespace App\Middleware;

use HybridPHP\Core\Middleware\AbstractMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CustomMiddleware extends AbstractMiddleware
{
    public function process(
        ServerRequestInterface $request, 
        RequestHandlerInterface $handler
    ): ResponseInterface {
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

### 4.6 数据库操作

#### 模型定义

```php
// app/Models/User.php
namespace App\Models;

use HybridPHP\Core\Database\ActiveRecord;

class User extends ActiveRecord
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email', 'password'];
    protected array $hidden = ['password'];
    
    // 关联关系
    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }
    
    // 访问器
    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }
    
    // 修改器
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = password_hash($value, PASSWORD_DEFAULT);
    }
}
```

#### 查询操作

```php
// 基础查询
$users = User::query()->where('status', 'active')->get()->await();

// 复杂查询
$users = User::query()
    ->where('age', '>', 18)
    ->where('city', 'Beijing')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get()->await();

// 关联查询
$users = User::query()
    ->with(['posts', 'profile'])
    ->where('status', 'active')
    ->get()->await();

// 聚合查询
$count = User::query()->where('status', 'active')->count()->await();
$avgAge = User::query()->avg('age')->await();
```

#### CRUD 操作

```php
// 创建
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
])->await();

// 查找
$user = User::find(1)->await();

// 更新
$user->name = 'Jane Doe';
$user->save()->await();

// 删除
$user->delete()->await();

// 事务
$db->transaction(function() {
    return async(function() {
        $user = User::create($userData)->await();
        Profile::create(['user_id' => $user->id] + $profileData)->await();
        return $user;
    });
})->await();
```

### 4.7 缓存系统

```php
use HybridPHP\Core\Cache\CacheManager;

// 基础操作
$cache->set('user:123', $userData, 3600)->await();  // 缓存1小时
$user = $cache->get('user:123')->await();
$cache->delete('user:123')->await();

// 记住模式（防止缓存击穿）
$user = $cache->remember('user:123', function() {
    return User::find(123);
}, 3600)->await();

// 标签缓存
$cache->tags(['users'])->set('user:123', $user)->await();
$cache->tags(['users'])->flush()->await();  // 清除所有用户缓存
```

### 4.8 数据库迁移

#### 创建迁移

```bash
php bin/hybrid make:migration create_users_table --create=users
```

#### 迁移文件

```php
// database/migrations/2024_01_22_120000_create_users_table.php
use HybridPHP\Core\Database\Migration\AbstractMigration;

class CreateUsersTable extends AbstractMigration
{
    public function up($database)
    {
        return $this->createTable('users', [
            'id' => ['type' => 'INT', 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'length' => 255],
            'email' => ['type' => 'VARCHAR', 'length' => 255],
            'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
        ], [
            'primary_key' => 'id',
            'unique' => ['email'],
        ]);
    }

    public function down($database)
    {
        return $this->dropTable('users');
    }
}
```

#### 运行迁移

```bash
php bin/hybrid migrate              # 运行迁移
php bin/hybrid migrate --rollback   # 回滚
php bin/hybrid migrate:status       # 查看状态
```

---

## 5. 进阶功能

### 5.1 认证系统

#### JWT 认证

```php
use function HybridPHP\Core\Auth\auth;

// 登录
$user = auth()->guard('jwt')->attempt([
    'username' => 'john@example.com',
    'password' => 'password123'
])->await();

// 生成 Token
$token = auth()->guard('jwt')->login($user)->await();

// 验证 Token
$user = auth()->guard('jwt')->validateToken($token)->await();
```

#### RBAC 权限控制

```php
use function HybridPHP\Core\Auth\rbac;

// 创建角色和权限
rbac()->createPermission('posts.write', 'Write posts')->await();
rbac()->createRole('editor', 'Content Editor', ['posts.write'])->await();

// 分配角色
rbac()->assignRole($user, 'editor')->await();

// 检查权限
if (rbac()->hasPermission($user, 'posts.write')->await()) {
    // 允许操作
}
```

#### 多因子认证 (MFA)

```php
use function HybridPHP\Core\Auth\mfa;

// 生成 TOTP 密钥
$secret = mfa()->generateSecret($user, 'totp')->await();

// 启用 MFA
mfa()->enableMethod($user, 'totp', $secret)->await();

// 验证码验证
$isValid = mfa()->verifyCode($user, '123456', 'totp')->await();
```

### 5.2 安全系统

#### 数据加密

```php
use HybridPHP\Core\Security\EncryptionService;

$encryption = new EncryptionService($key);

// 加密
$encrypted = $encryption->encrypt('sensitive data')->await();

// 解密
$decrypted = $encryption->decrypt($encrypted)->await();

// 数据脱敏
$masked = $encryption->maskSensitiveData('john@example.com', 4);
// 结果: "jo**@example.com"
```

#### 安全中间件

```php
// 内置安全中间件
$securityManager->registerGlobalSecurity();

// 包含:
// - CSRF 保护
// - XSS 防护
// - SQL 注入防护
// - 安全头设置
// - 内容安全策略 (CSP)
```

### 5.3 日志系统

```php
use Psr\Log\LoggerInterface;

class UserController
{
    public function __construct(private LoggerInterface $logger) {}
    
    public function create(Request $request): Response
    {
        $this->logger->info('Creating user', [
            'email' => $data['email'],
            'ip' => $request->getClientIp(),
        ]);
        
        try {
            $user = User::create($data)->await();
            $this->logger->info('User created', ['user_id' => $user->id]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create user', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

### 5.4 健康检查与监控

```php
// 健康检查端点
GET /health          # 基础健康检查
GET /health/ready    # 就绪检查
GET /metrics         # Prometheus 指标

// 自定义健康检查
$healthManager->registerCheck('database', function() {
    return $this->db->ping() ? 'healthy' : 'unhealthy';
});
```

### 5.5 调试工具

#### 性能分析

```php
use HybridPHP\Core\Debug\PerformanceProfiler;

$profiler = new PerformanceProfiler();

// 计时
$profiler->startTimer('database_query');
$result = $database->query('SELECT * FROM users');
$profiler->stopTimer('database_query');

// 获取报告
$report = $profiler->getDetailedReport();
```

#### 查询分析

```php
use HybridPHP\Core\Debug\QueryAnalyzer;

$analyzer = new QueryAnalyzer();

// 记录查询
$analyzer->recordQuery($sql, $params, $executionTime);

// 获取慢查询
$slowQueries = $analyzer->getSlowQueries();

// 获取重复查询
$duplicates = $analyzer->getDuplicateQueries();
```

#### 调试命令

```bash
php debug.php status      # 查看调试状态
php debug.php profiler    # 性能分析报告
php debug.php queries     # 查询分析报告
php debug.php export json # 导出调试数据
```

---

## 6. 生产部署

### 6.1 环境配置

```env
# .env.production
APP_ENV=production
APP_DEBUG=false

# 数据库
DB_HOST=your-db-host
DB_DATABASE=your-db-name
DB_USERNAME=your-db-user
DB_PASSWORD=your-db-password

# Redis
REDIS_HOST=your-redis-host

# 服务器
HTTP_HOST=0.0.0.0
HTTP_PORT=8080
HTTP_WORKERS=8
```

### 6.2 Docker 部署

```bash
# 构建镜像
docker build -t hybridphp-app .

# 运行容器
docker-compose up -d

# 扩展服务
docker-compose up --scale app=3
```

### 6.3 Kubernetes 部署

```bash
# 部署到 K8s
kubectl apply -f k8s/

# 蓝绿部署
./scripts/deploy.sh -e production -t blue-green

# 监控状态
kubectl get pods -l app=hybridphp
```

### 6.4 进程管理 (Supervisor)

```ini
# /etc/supervisor/conf.d/hybridphp.conf
[program:hybridphp]
command=/usr/bin/php /var/www/hybridphp/bootstrap.php
directory=/var/www/hybridphp
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/hybridphp.log
```

### 6.5 Nginx 反向代理

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;
    
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

---

## 7. 最佳实践

### 7.1 代码组织

```
✅ 推荐做法:
- 遵循 PSR-4 自动加载标准
- 使用命名空间组织代码
- 单一职责原则
- 依赖注入而非硬编码

❌ 避免做法:
- 在控制器中写业务逻辑
- 直接 new 依赖对象
- 在模型中写复杂查询
```

### 7.2 性能优化

| 优化项 | 方法 |
|-------|------|
| 数据库 | 使用连接池、合理索引、避免 N+1 查询 |
| 缓存 | 多级缓存、合理 TTL、缓存预热 |
| 异步 | 耗时操作异步处理、使用协程 |
| 配置 | 启用 OPcache、路由缓存 |

### 7.3 安全检查清单

- [ ] 输入验证和数据清理
- [ ] 使用参数化查询防止 SQL 注入
- [ ] 实施 CSRF 保护
- [ ] 启用 HTTPS
- [ ] 设置安全响应头
- [ ] 定期更新依赖包
- [ ] 敏感数据加密存储
- [ ] 实施访问控制和权限检查

### 7.4 测试策略

```bash
# 运行所有测试
composer run test

# 单元测试
composer run test:unit

# 集成测试
composer run test:feature

# 代码覆盖率
composer run test:coverage

# 代码质量检查
composer run cs          # 代码风格
composer run analyze     # 静态分析
```

---

## 8. 常见问题

### Q1: 应用无法启动？

```bash
# 检查日志
tail -f storage/logs/app.log

# 检查端口占用
netstat -tlnp | grep 8080

# 检查 PHP 扩展
php -m | grep pcntl
```

### Q2: 数据库连接失败？

```bash
# 测试连接
mysql -h $DB_HOST -u $DB_USERNAME -p$DB_PASSWORD $DB_DATABASE

# 检查配置
cat .env | grep DB_
```

### Q3: 内存不足？

```bash
# 检查内存使用
free -h

# 调整 PHP 内存限制
echo "memory_limit = 2G" >> /etc/php/8.1/cli/php.ini
```

### Q4: 如何调试异步代码？

```php
// 使用日志
$this->logger->debug('Async operation started', ['id' => $id]);

// 使用协程调试器
$debugger->registerCoroutine('task_name', 'Description', $callback);
```

---

## 📚 更多资源

| 文档 | 说明 |
|------|------|
| [架构设计](ARCHITECTURE.md) | 技术架构和设计理念 |
| [安全系统](SECURITY_SYSTEM.md) | 安全配置和最佳实践 |
| [缓存系统](CACHE_SYSTEM.md) | 缓存配置和使用 |
| [认证系统](AUTHENTICATION.md) | 认证授权详细指南 |
| [部署指南](DEPLOYMENT_GUIDE.md) | 生产环境部署 |
| [CI/CD](CI_CD_PIPELINE.md) | 自动化部署流水线 |
| [调试工具](DEBUG_TOOLS.md) | 调试和性能分析 |

---

## CLI 命令速查

```bash
# 项目管理
php bin/hybrid serve                # 启动开发服务器
php bin/hybrid key:generate         # 生成应用密钥

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
php bin/hybrid metrics:export       # 导出指标
```

---

> 💡 **提示**: 遇到问题？查看 [GitHub Issues](https://github.com/hybridphp/framework/issues) 或加入社区讨论。

**Happy Coding with HybridPHP! 🚀**
