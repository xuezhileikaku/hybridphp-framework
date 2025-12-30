# CLI 命令参考

HybridPHP 提供强大的命令行工具集，用于项目管理、代码生成、数据库操作等。

## 命令概览

### 项目管理

```bash
php bin/hybrid serve                    # 启动开发服务器
php bin/hybrid key:generate             # 生成应用密钥
php bin/hybrid info                     # 显示框架信息
```

### 代码生成

```bash
php bin/hybrid make:controller <name>   # 生成控制器
php bin/hybrid make:model <name>        # 生成模型
php bin/hybrid make:middleware <name>   # 生成中间件
php bin/hybrid make:migration <name>    # 生成迁移文件
php bin/hybrid make:seeder <name>       # 生成数据填充
php bin/hybrid make:project <name>      # 创建新项目
```

### 数据库管理

```bash
php bin/hybrid migrate                  # 运行迁移
php bin/hybrid migrate --rollback       # 回滚迁移
php bin/hybrid migrate:status           # 迁移状态
php bin/hybrid seed                     # 数据填充
php bin/hybrid seed --class=UserSeeder  # 指定填充类
```

### 缓存管理

```bash
php bin/hybrid cache clear              # 清除所有缓存
php bin/hybrid cache clear --store=redis # 清除指定存储
php bin/hybrid cache clear --tags=users # 按标签清除
php bin/hybrid cache stats              # 缓存统计
php bin/hybrid cache health             # 健康检查
```

### 安全工具

```bash
php bin/hybrid security key:generate    # 生成加密密钥
php bin/hybrid security key:rotate      # 轮换密钥
php bin/hybrid security key:list        # 列出密钥
php bin/hybrid security audit:clean     # 清理审计日志
php bin/hybrid security tls:generate    # 生成 TLS 证书
php bin/hybrid security tls:check       # 检查 TLS 配置
```

### 监控工具

```bash
php bin/hybrid health:check             # 健康检查
php bin/hybrid metrics:export           # 导出指标
php bin/hybrid monitoring:status        # 监控状态
```

### 调试工具

```bash
php bin/hybrid debug status             # 调试状态
php bin/hybrid debug profiler           # 性能分析
php bin/hybrid debug queries            # 查询分析
php bin/hybrid debug export json        # 导出调试数据
```

## 详细说明

### make:controller

生成控制器类。

```bash
php bin/hybrid make:controller User
php bin/hybrid make:controller Api/UserController
php bin/hybrid make:controller User --resource  # 资源控制器
```

生成文件：`app/Controllers/UserController.php`

### make:model

生成模型类。

```bash
php bin/hybrid make:model User
php bin/hybrid make:model User --migration  # 同时生成迁移
php bin/hybrid make:model User --table=users
```

生成文件：`app/Models/User.php`

### make:middleware

生成中间件类。

```bash
php bin/hybrid make:middleware Auth
php bin/hybrid make:middleware RateLimit
```

生成文件：`app/Middleware/AuthMiddleware.php`

### make:migration

生成数据库迁移文件。

```bash
php bin/hybrid make:migration create_users_table
php bin/hybrid make:migration create_users_table --create=users
php bin/hybrid make:migration add_email_to_users --table=users
```

生成文件：`database/migrations/2024_01_01_000000_create_users_table.php`

### migrate

运行数据库迁移。

```bash
php bin/hybrid migrate                  # 运行所有待执行迁移
php bin/hybrid migrate --rollback       # 回滚最后一批迁移
php bin/hybrid migrate --rollback=3     # 回滚指定数量
php bin/hybrid migrate --fresh          # 删除所有表并重新迁移
php bin/hybrid migrate --seed           # 迁移后填充数据
```

### seed

运行数据填充。

```bash
php bin/hybrid seed                     # 运行所有填充
php bin/hybrid seed --class=UserSeeder  # 运行指定填充类
```

### cache

缓存管理命令。

```bash
php bin/hybrid cache clear              # 清除所有缓存
php bin/hybrid cache clear --store=redis
php bin/hybrid cache clear --tags=users,posts
php bin/hybrid cache stats              # 显示缓存统计
php bin/hybrid cache stats --store=multilevel
php bin/hybrid cache health             # 检查缓存健康状态
php bin/hybrid cache get --key=user:123 # 获取缓存值
php bin/hybrid cache delete --key=user:123
```

### health:check

执行健康检查。

```bash
php bin/hybrid health:check             # 完整健康检查
php bin/hybrid health:check --component=database
php bin/hybrid health:check --format=json
```

输出示例：
```
Health Check Results:
┌─────────────┬─────────┬──────────┐
│ Component   │ Status  │ Time     │
├─────────────┼─────────┼──────────┤
│ Application │ Healthy │ 2ms      │
│ Database    │ Healthy │ 15ms     │
│ Cache       │ Healthy │ 5ms      │
│ Redis       │ Healthy │ 3ms      │
└─────────────┴─────────┴──────────┘
Overall: HEALTHY
```

## 自定义命令

### 创建命令

```php
namespace App\Commands;

use HybridPHP\Core\Console\Command;

class SendEmailCommand extends Command
{
    protected string $name = 'email:send';
    protected string $description = 'Send email to users';
    
    public function handle(): int
    {
        $this->info('Sending emails...');
        
        // 业务逻辑
        
        $this->success('Emails sent successfully!');
        return 0;
    }
}
```

### 注册命令

```php
// bootstrap.php
$app->registerCommand(SendEmailCommand::class);
```

### 命令输出

```php
$this->info('Information message');
$this->success('Success message');
$this->warning('Warning message');
$this->error('Error message');

// 表格输出
$this->table(['Name', 'Email'], [
    ['John', 'john@example.com'],
    ['Jane', 'jane@example.com'],
]);

// 进度条
$bar = $this->createProgressBar(100);
for ($i = 0; $i < 100; $i++) {
    $bar->advance();
}
$bar->finish();
```

## 环境变量

命令行工具支持以下环境变量：

| 变量 | 说明 | 默认值 |
|------|------|--------|
| `APP_ENV` | 应用环境 | `development` |
| `APP_DEBUG` | 调试模式 | `true` |
| `DB_HOST` | 数据库主机 | `localhost` |
| `REDIS_HOST` | Redis 主机 | `localhost` |

## 下一步

- [配置参考](./CONFIG.md) - 配置项完整参考
- [新手入门](../guide/GETTING_STARTED.md) - 快速开始
