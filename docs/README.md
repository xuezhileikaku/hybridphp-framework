# HybridPHP Framework 文档中心

> 融合 Yii2 易用性、Workerman 多进程能力、AMPHP 异步特性的下一代高性能 PHP 框架

---

## 📚 文档导航

### 🚀 快速入门
| 文档 | 说明 |
|------|------|
| [新手入门指南](./guide/GETTING_STARTED.md) | 从零开始学习框架 |
| [安装部署](./guide/INSTALLATION.md) | 环境要求与安装步骤 |
| [目录结构](./guide/DIRECTORY_STRUCTURE.md) | 项目目录说明 |
| [配置管理](./guide/CONFIGURATION.md) | 配置文件详解 |

### 🏗️ 框架架构
| 文档 | 说明 |
|------|------|
| [架构设计](./architecture/OVERVIEW.md) | 框架设计理念与架构 |
| [依赖注入容器](./architecture/CONTAINER.md) | PSR-11 容器实现 |
| [生命周期](./architecture/LIFECYCLE.md) | 应用生命周期管理 |
| [服务提供者](./architecture/SERVICE_PROVIDER.md) | 服务注册与启动 |

### 🔧 核心组件
| 文档 | 说明 |
|------|------|
| [路由系统](./components/ROUTING.md) | 高性能路由匹配 |
| [中间件](./components/MIDDLEWARE.md) | PSR-15 中间件系统 |
| [HTTP 处理](./components/HTTP.md) | 请求与响应处理 |
| [数据库 ORM](./components/DATABASE.md) | 异步数据库操作 |
| [缓存系统](./components/CACHE.md) | 多级分布式缓存 |
| [认证授权](./components/AUTH.md) | JWT/RBAC/MFA |
| [安全系统](./components/SECURITY.md) | 数据加密与审计 |
| [日志系统](./components/LOGGING.md) | 异步结构化日志 |

### 🌐 高级特性
| 文档 | 说明 |
|------|------|
| [WebSocket](./advanced/WEBSOCKET.md) | 实时通信服务 |
| [gRPC 服务](./advanced/GRPC.md) | 微服务 RPC 通信 |
| [GraphQL](./advanced/GRAPHQL.md) | GraphQL API 支持 |
| [HTTP/2](./advanced/HTTP2.md) | HTTP/2 与 Server Push |
| [分布式追踪](./advanced/TRACING.md) | OpenTelemetry 集成 |
| [健康检查](./advanced/HEALTH.md) | 应用健康监控 |

### 📱 实战应用
| 文档 | 说明 |
|------|------|
| [IM 即时通讯](./applications/IM_SYSTEM.md) | 高并发 IM 系统实现 |
| [实时推送](./applications/REALTIME_PUSH.md) | 消息推送系统 |
| [微服务架构](./applications/MICROSERVICES.md) | 分布式系统设计 |
| [API 网关](./applications/API_GATEWAY.md) | 统一 API 入口 |

### 🚢 部署运维
| 文档 | 说明 |
|------|------|
| [Docker 部署](./deployment/DOCKER.md) | 容器化部署 |
| [Kubernetes](./deployment/KUBERNETES.md) | K8s 集群部署 |
| [性能优化](./deployment/PERFORMANCE.md) | 性能调优指南 |
| [监控告警](./deployment/MONITORING.md) | 监控系统集成 |

### 📖 API 参考
| 文档 | 说明 |
|------|------|
| [API 文档](./api/index.html) | 自动生成的 API 文档 |
| [CLI 命令](./reference/CLI.md) | 命令行工具参考 |
| [配置参考](./reference/CONFIG.md) | 配置项完整参考 |

---

## 🎯 快速开始

```bash
# 克隆项目
git clone https://github.com/hybridphp/framework.git
cd framework

# 安装依赖
composer install

# 配置环境
cp .env.example .env

# 启动服务
php bootstrap.php
```

访问 http://localhost:8080 查看效果。

---

## 💡 框架特色

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
│  • Docker/K8s部署   • 分布式追踪    • 健康检查             │
└─────────────────────────────────────────────────────────────┘
```

---

## 📊 性能基准

| 指标 | 结果 |
|------|------|
| QPS | 15,000+ |
| 平均响应时间 | 50ms |
| 99%响应时间 | 200ms |
| 内存使用 | 256MB |
| 错误率 | 0% |

*测试环境：4核8GB服务器，1000并发用户*

---

## 🤝 贡献与支持

- **GitHub**: https://github.com/hybridphp/framework
- **Issues**: https://github.com/hybridphp/framework/issues
- **文档贡献**: 欢迎提交 PR 改进文档

---

**Happy Coding with HybridPHP! 🚀**
