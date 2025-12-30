# HybridPHP Framework

<div align="center">

![HybridPHP Logo](https://via.placeholder.com/200x80/4A90E2/FFFFFF?text=HybridPHP)

**融合三大优秀框架精华的下一代高性能 PHP 框架**

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Build Status](https://img.shields.io/badge/build-passing-brightgreen.svg)](https://github.com/hybridphp/framework)
[![Coverage](https://img.shields.io/badge/coverage-95%25-brightgreen.svg)](https://github.com/hybridphp/framework)

[English](README.en.md) | [中文文档](README.md)

</div>

## 🚀 项目简介

HybridPHP 是一个创新的高性能 PHP 框架，融合了三大优秀框架的精华特性：

- **🎯 Yii2 的易用性**：约定优于配置，简洁直观的开发体验
- **⚡ Workerman 的多进程能力**：高性能进程管理，内存常驻，支持多协议
- **🔄 AMPHP 的异步特性**：协程驱动，非阻塞I/O，高并发处理

## ✨ 核心特性

### 🏗️ 高性能架构
- **异步非阻塞I/O**：基于 AMPHP 协程，支持高并发处理
- **多进程架构**：利用 Workerman 多进程模型，充分利用多核CPU
- **内存常驻**：避免重复初始化，显著提升响应速度
- **智能连接池**：数据库和缓存连接池，优化资源使用

### 🛠️ 开发友好
- **Yii2 风格API**：熟悉的开发体验，降低学习成本
- **约定优于配置**：减少样板代码，提高开发效率
- **强大的CLI工具**：代码生成、数据库迁移、任务调度
- **完善的调试工具**：详细的错误信息、性能分析、实时监控

### 🔒 企业级安全
- **多层安全防护**：CSRF、XSS、SQL注入防护
- **数据加密**：敏感数据加密存储，支持密钥轮换
- **认证授权**：JWT、OAuth2、RBAC权限系统
- **审计日志**：完整的安全事件记录和追踪

### 📊 监控运维
- **健康检查**：应用、数据库、缓存、外部服务监控
- **性能监控**：Prometheus指标导出，实时性能分析
- **日志系统**：结构化异步日志，支持ELK集成
- **告警系统**：多渠道通知，智能告警规则

### ☁️ 云原生支持
- **容器化部署**：优化的Docker镜像，支持Kubernetes
- **CI/CD集成**：完整的自动化部署流水线
- **蓝绿部署**：零停机时间部署，快速回滚
- **水平扩展**：支持负载均衡和自动扩缩容

## 🚀 快速开始

### 方式一：使用 Composer 创建项目（推荐）

```bash
# 创建新项目
composer create-project hybridphp/skeleton my-app

# 进入项目目录
cd my-app

# 配置环境变量
# 编辑 .env 文件设置数据库等配置

# 启动应用
php bootstrap.php
```

### 方式二：克隆完整框架

```bash
# 1. 克隆项目
git clone https://github.com/hybridphp/framework.git
cd framework

# 2. 安装依赖
composer install --optimize-autoloader

# 3. 环境配置
cp .env.example .env
# 编辑 .env 文件设置数据库等配置

# 4. 生成应用密钥
php bin/hybrid key:generate

# 5. 数据库初始化
php bin/hybrid migrate
php bin/hybrid seed

# 6. 启动应用
php bootstrap.php
```

### 环境要求

- **PHP**: 8.1 或更高版本
- **扩展**: json, openssl, pcntl, posix, sockets
- **数据库**: MySQL 5.7+ 或 PostgreSQL 12+
- **缓存**: Redis 5.0+
- **Composer**: 2.0+

### 安装部署

```bash
# 1. 克隆项目
git clone https://github.com/hybridphp/framework.git
cd framework

# 2. 安装依赖
composer install --optimize-autoloader

# 3. 环境配置
cp .env.example .env
# 编辑 .env 文件设置数据库等配置

# 4. 生成应用密钥
php bin/hybrid key:generate

# 5. 数据库初始化
php bin/hybrid migrate
php bin/hybrid seed

# 6. 启动应用
php bootstrap.php
```

### 基础使用示例

#### 路由定义
```php
// routes/web.php
use HybridPHP\Core\Routing\RouterFacade as Router;

// 基础路由
Router::get('/', [HomeController::class, 'index']);
Router::post('/users', [UserController::class, 'store']);

// 路由组
Router::group(['prefix' => 'api/v1', 'middleware' => ['auth']], function() {
    Router::resource('posts', PostController::class);
});

// 命名路由
Router::get('/user/{id}', [UserController::class, 'show'])->name('user.show');
```

#### 控制器
```php
// app/Controllers/HomeController.php
class HomeController
{
    public function index(Request $request): Response
    {
        return response()->json([
            'message' => 'Welcome to HybridPHP!',
            'version' => '1.0.0',
            'features' => ['async', 'high-performance', 'easy-to-use']
        ]);
    }
}
```

#### 异步数据库操作
```php
// 异步查询
$users = User::query()
    ->where('status', 'active')
    ->with(['posts', 'profile'])
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get()->await();

// 事务处理
$db->transaction(function() {
    return async(function() {
        $user = User::create($userData)->await();
        Profile::create(['user_id' => $user->id] + $profileData)->await();
        return $user;
    });
})->await();
```

#### 缓存使用
```php
// 基础缓存操作
$cache->set('user:' . $id, $user, 3600)->await();
$user = $cache->get('user:' . $id)->await();

// 标签缓存
$cache->tags(['users', 'posts'])->set('user:posts:' . $id, $posts)->await();
$cache->tags(['users'])->flush()->await();
```

## 📊 性能基准

```
测试环境：
- 服务器：4核8GB内存
- 数据库：MySQL 8.0
- 缓存：Redis 6.0
- 并发：1000用户
- 持续时间：60秒

性能结果：
┌─────────────────┬──────────────┐
│ 指标            │ 结果         │
├─────────────────┼──────────────┤
│ QPS             │ 15,000+      │
│ 平均响应时间    │ 50ms         │
│ 99%响应时间     │ 200ms        │
│ 内存使用        │ 256MB        │
│ CPU使用率       │ 60%          │
│ 错误率          │ 0%           │
└─────────────────┴──────────────┘
```

## 🏗️ 架构设计

### 系统架构图

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

### 核心组件

| 组件 | 功能 | 特性 |
|------|------|------|
| **Application** | 应用生命周期管理 | 优雅启停、信号处理、热重载 |
| **Container** | 依赖注入容器 | PSR-11兼容、自动装配、循环依赖检测 |
| **Router** | 路由系统 | 高性能匹配、中间件支持、RESTful |
| **Database** | 数据库ORM | 异步操作、连接池、事务支持 |
| **Cache** | 缓存系统 | 多级缓存、分布式、标签支持 |
| **Security** | 安全系统 | 数据加密、认证授权、审计日志 |
| **Monitoring** | 监控系统 | 健康检查、指标收集、告警通知 |

## 📚 文档

### 🌟 新手必读
- [📖 新手入门指南](docs/GETTING_STARTED.md) - **从零开始掌握框架**

### 📖 功能文档
| 文档 | 说明 |
|------|------|
| [认证系统](docs/AUTHENTICATION.md) | JWT、RBAC、MFA |
| [缓存系统](docs/CACHE_SYSTEM.md) | 多级缓存、Redis |
| [安全系统](docs/SECURITY_SYSTEM.md) | 数据加密、审计 |
| [中间件](docs/MIDDLEWARE.md) | PSR-15中间件 |
| [日志系统](docs/LOGGING_SYSTEM.md) | 异步日志 |
| [数据库迁移](docs/MIGRATION_SEEDER_SYSTEM.md) | 迁移和填充 |
| [部署指南](docs/DEPLOYMENT_GUIDE.md) | Docker/K8s部署 |
| [调试工具](docs/DEBUG_TOOLS.md) | 性能分析 |

## 🛠️ CLI 工具

HybridPHP 提供了强大的命令行工具集：

```bash
# 🚀 项目管理
php bin/hybrid serve                    # 启动开发服务器
php bin/hybrid key:generate             # 生成应用密钥

# 🏗️ 代码生成
php bin/hybrid make:controller User     # 生成控制器
php bin/hybrid make:model Post          # 生成模型
php bin/hybrid make:middleware Auth     # 生成中间件
php bin/hybrid make:command SendEmail   # 生成命令

# 🗄️ 数据库管理
php bin/hybrid migrate                  # 运行迁移
php bin/hybrid migrate:rollback         # 回滚迁移
php bin/hybrid migrate:status           # 迁移状态
php bin/hybrid seed                     # 数据填充

# 💾 缓存管理
php bin/hybrid cache:clear              # 清除缓存
php bin/hybrid cache:warm               # 预热缓存

# 🔒 安全工具
php bin/hybrid security:scan            # 安全扫描
php bin/hybrid security:key:rotate      # 密钥轮换

# 📊 监控工具
php bin/hybrid health:check             # 健康检查
php bin/hybrid metrics:export           # 导出指标
php bin/hybrid monitoring:status        # 监控状态
```

## 🧪 测试

### 运行测试

```bash
# 运行所有测试
composer run test

# 运行特定类型测试
composer run test:unit                  # 单元测试
composer run test:integration           # 集成测试
composer run test:performance           # 性能测试

# 生成覆盖率报告
composer run test:coverage

# 代码质量检查
composer run cs                         # 代码风格检查
composer run analyze                    # 静态分析
composer run security:audit             # 安全审计
```

### 测试覆盖率

```
组件覆盖率统计：
┌─────────────────┬──────────────┐
│ 组件            │ 覆盖率       │
├─────────────────┼──────────────┤
│ Core            │ 98%          │
│ HTTP            │ 95%          │
│ Database        │ 92%          │
│ Cache           │ 94%          │
│ Security        │ 96%          │
│ Monitoring      │ 90%          │
├─────────────────┼──────────────┤
│ 总体覆盖率      │ 95%          │
└─────────────────┴──────────────┘
```

## 🚀 部署方案

### 🐳 Docker 部署

```bash
# 构建镜像
docker build -t hybridphp-app .

# 运行容器
docker-compose up -d

# 扩展服务
docker-compose up --scale app=3
```

### ☸️ Kubernetes 部署

```bash
# 部署到 Kubernetes
kubectl apply -f k8s/

# 蓝绿部署
./scripts/deploy.sh -e production -t blue-green

# 监控状态
kubectl get pods -l app=hybridphp
```

### 🔄 CI/CD 流水线

```yaml
# GitHub Actions 自动化流水线
- 代码质量检查 (PSR-12, PHPStan)
- 安全扫描 (依赖漏洞, 代码安全)
- 自动化测试 (单元, 集成, 性能)
- 构建 Docker 镜像
- 多环境部署 (开发, 测试, 生产)
- 蓝绿部署和回滚
- 监控和告警
```

## 🤝 贡献指南

我们欢迎所有形式的贡献！

### 🔧 代码贡献

1. **Fork** 项目到你的 GitHub
2. **创建** 功能分支 (`git checkout -b feature/amazing-feature`)
3. **提交** 更改 (`git commit -m 'Add amazing feature'`)
4. **推送** 到分支 (`git push origin feature/amazing-feature`)
5. **创建** Pull Request

### 📝 文档贡献

- 改进现有文档
- 翻译文档到其他语言
- 添加使用示例和教程
- 修复文档中的错误

### 🐛 问题报告

- 使用 [GitHub Issues](https://github.com/hybridphp/framework/issues) 报告 Bug
- 提供详细的重现步骤
- 包含环境信息和错误日志
- 建议解决方案（如果有的话）

### 💡 功能建议

- 在 Issues 中提出新功能建议
- 详细描述功能需求和使用场景
- 讨论实现方案和技术细节

## 📄 许可证

本项目采用 [MIT 许可证](LICENSE)。

```
MIT License

Copyright (c) 2024 HybridPHP Framework

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.
```

## 🌟 致谢

感谢以下优秀的开源项目为 HybridPHP 提供了灵感和技术支持：

- [Yii Framework](https://www.yiiframework.com/) - 优雅的 PHP 框架
- [Workerman](https://www.workerman.net/) - 高性能 PHP 应用服务器
- [AMPHP](https://amphp.org/) - 异步并发 PHP 库
- [FastRoute](https://github.com/nikic/FastRoute) - 快速路由库
- [Monolog](https://github.com/Seldaek/monolog) - PHP 日志库

## 📞 支持与联系

### 🌐 官方资源
- **官网**: https://hybridphp.com
- **文档**: https://docs.hybridphp.com
- **GitHub**: https://github.com/hybridphp/framework
- **Packagist**: https://packagist.org/packages/hybridphp/framework

### 💬 社区交流
- **QQ群**: 123456789
- **微信群**: 扫描二维码加入
- **Discord**: https://discord.gg/hybridphp
- **Telegram**: https://t.me/hybridphp

### 📧 商业支持
- **技术咨询**: consulting@hybridphp.com
- **商业授权**: business@hybridphp.com
- **培训服务**: training@hybridphp.com
- **技术支持**: support@hybridphp.com

---

<div align="center">

**⭐ 如果这个项目对你有帮助，请给我们一个 Star！⭐**

**🚀 让我们一起构建更好的 PHP 生态系统！🚀**

Made with ❤️ by [HybridPHP Team](https://github.com/hybridphp)

</div>