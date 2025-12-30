# 认证授权系统

HybridPHP 提供完整的认证授权系统，支持 JWT、Session、OAuth2 多种认证方式，以及 RBAC 权限控制和 MFA 多因子认证。

## 核心特性

- **多种认证守卫**: JWT、Session、OAuth2
- **RBAC 权限控制**: 角色和权限管理
- **MFA 多因子认证**: TOTP、Email、SMS
- **中间件集成**: 路由级别的权限控制
- **异步支持**: 全异步操作

## 快速开始

### 配置

```php
// config/auth.php
return [
    'default' => 'jwt',
    'guards' => [
        'jwt' => [
            'driver' => 'jwt',
            'provider' => 'users',
            'secret' => env('JWT_SECRET'),
            'ttl' => 3600,
        ],
        'session' => [
            'driver' => 'session',
            'provider' => 'users',
            'lifetime' => 7200,
        ],
    ],
    'providers' => [
        'users' => [
            'driver' => 'database',
            'model' => \App\Models\User::class,
        ],
    ],
    'mfa' => [
        'enabled' => true,
        'methods' => [
            'totp' => ['enabled' => true],
            'email' => ['enabled' => true],
        ],
    ],
];
```

## JWT 认证

```php
use function HybridPHP\Core\Auth\auth;

// 登录认证
$user = auth()->guard('jwt')->attempt([
    'username' => 'john@example.com',
    'password' => 'password123'
])->await();

// 生成 Token
$token = auth()->guard('jwt')->login($user)->await();

// 验证 Token
$user = auth()->guard('jwt')->validateToken($token)->await();

// 刷新 Token
$newToken = auth()->guard('jwt')->refresh($token)->await();
```

## RBAC 权限控制

### 创建角色和权限

```php
use function HybridPHP\Core\Auth\rbac;

// 创建权限
rbac()->createPermission('posts.read', 'Read posts')->await();
rbac()->createPermission('posts.write', 'Write posts')->await();
rbac()->createPermission('posts.delete', 'Delete posts')->await();

// 创建角色
rbac()->createRole('editor', 'Content Editor', [
    'posts.read', 'posts.write'
])->await();

rbac()->createRole('admin', 'Administrator', [
    'posts.read', 'posts.write', 'posts.delete'
])->await();
```

### 分配和检查权限

```php
// 分配角色
rbac()->assignRole($user, 'editor')->await();

// 授予直接权限
rbac()->grantPermission($user, 'posts.delete')->await();

// 检查权限
$hasPermission = rbac()->hasPermission($user, 'posts.write')->await();
$hasRole = rbac()->hasRole($user, 'admin')->await();
```

## MFA 多因子认证

### TOTP 认证

```php
use function HybridPHP\Core\Auth\mfa;

// 生成密钥
$secret = mfa()->generateSecret($user, 'totp')->await();

// 获取二维码 URL
$qrCodeUrl = mfa()->getQRCodeUrl($user, $secret);

// 启用 TOTP
mfa()->enableMethod($user, 'totp', $secret)->await();

// 验证码验证
$isValid = mfa()->verifyCode($user, '123456', 'totp')->await();
```

### 备用码

```php
// 生成备用码
$backupCodes = mfa()->generateBackupCodes($user)->await();

// 验证备用码
$isValid = mfa()->verifyBackupCode($user, 'ABCD-1234')->await();
```

## 中间件

### 认证中间件

```php
use HybridPHP\Core\Auth\Middleware\AuthMiddleware;

Router::group(['middleware' => [AuthMiddleware::class]], function () {
    Router::get('/dashboard', [DashboardController::class, 'index']);
});
```

### 权限中间件

```php
use HybridPHP\Core\Auth\Middleware\PermissionMiddleware;

Router::group([
    'middleware' => [new PermissionMiddleware(rbac(), 'posts.write')]
], function () {
    Router::post('/posts', [PostController::class, 'create']);
});
```

### 角色中间件

```php
use HybridPHP\Core\Auth\Middleware\RoleMiddleware;

Router::group([
    'middleware' => [new RoleMiddleware(rbac(), ['admin', 'moderator'])]
], function () {
    Router::get('/admin', [AdminController::class, 'index']);
});
```

## 辅助函数

```php
// 认证相关
$authManager = auth();
$userComponent = user();
$rbacManager = rbac();
$mfaManager = mfa();

// 快捷检查
$canEdit = can('posts.edit')->await();
$isAdmin = hasRole('admin')->await();
$isGuest = isGuest()->await();
$currentUser = currentUser()->await();

// 密码工具
$hash = hashPassword('password123');
$isValid = verifyPassword('password123', $hash);
```

## API 认证流程

```javascript
// 1. 登录
const response = await fetch('/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        username: 'john@example.com',
        password: 'password123'
    })
});

const { token, mfa_required } = await response.json();

// 2. MFA 验证（如需要）
if (mfa_required) {
    await fetch('/auth/mfa/verify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code: '123456', method: 'totp' })
    });
}

// 3. 使用 Token 访问 API
const apiResponse = await fetch('/api/v1/users', {
    headers: { 'Authorization': `Bearer ${token}` }
});
```

## 安全最佳实践

1. **使用 HTTPS**: 生产环境必须使用 HTTPS
2. **强密钥**: 使用强随机 JWT 密钥
3. **合理过期**: 设置适当的 Token 过期时间
4. **MFA 保护**: 管理员账户启用 MFA
5. **权限缓存**: 启用权限缓存提升性能

## 下一步

- [安全系统](./SECURITY.md) - 数据加密与审计
- [中间件](./MIDDLEWARE.md) - 中间件详解
