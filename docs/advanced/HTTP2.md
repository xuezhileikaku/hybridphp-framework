# HTTP/2 支持

HybridPHP 提供完整的 HTTP/2 支持，包括 TLS 加密、Server Push、多路复用和 HPACK 头部压缩。

## 核心特性

- **HTTP/2 协议**: 完整的 HTTP/2 实现
- **Server Push**: 主动推送资源
- **多路复用**: 单连接多流并发
- **HPACK 压缩**: 高效头部压缩
- **TLS 1.2/1.3**: 现代加密支持
- **ALPN 协商**: 自动协议协商

## 快速开始

### 生成 SSL 证书

```bash
# 开发环境自签名证书
php scripts/generate-ssl-cert.php localhost
```

### 配置

```env
HTTP2_ENABLED=true
HTTP2_HOST=0.0.0.0
HTTP2_PORT=8443
HTTP2_SERVER_PUSH=true

TLS_CERT_PATH=storage/ssl/server.crt
TLS_KEY_PATH=storage/ssl/server.key
TLS_ALLOW_SELF_SIGNED=true
```

### 创建服务器

```php
use HybridPHP\Core\Server\Http2Server;
use HybridPHP\Core\Routing\Router;
use HybridPHP\Core\Container;

$container = new Container();
$router = new Router();

$router->get('/', function($request, $params) {
    return ['message' => 'Hello HTTP/2!'];
});

$server = new Http2Server($router, $container, [
    'host' => '0.0.0.0',
    'port' => 8443,
    'enable_http2' => true,
    'cert_path' => 'storage/ssl/server.crt',
    'key_path' => 'storage/ssl/server.key',
]);

$server->listen();
```

## Server Push

### 工作原理

```
1. 客户端请求页面 (如 /index.html)
2. 服务器分析响应，识别关键资源
3. 服务器发送 Link 头部预加载提示
4. HTTP/2 代理或浏览器推送资源
5. 资源在浏览器解析 HTML 前到达
```

### 自动资源检测

服务器自动检测并推送 HTML 中的资源：

- CSS 文件 (`<link rel="stylesheet">`)
- JavaScript 文件 (`<script src="...">`)
- 预加载提示 (`<link rel="preload">`)
- 字体文件 (`@font-face`)
- 关键图片 (`data-priority="high"`)

### 手动注册资源

```php
$pushManager = $server->getPushManager();

// 注册单个资源
$pushManager->registerResource('/css/app.css', 'style');
$pushManager->registerResource('/js/app.js', 'script');
$pushManager->registerResource('/fonts/main.woff2', 'font', [
    'crossorigin' => 'anonymous',
    'priority' => 32,
]);

// 批量注册
$pushManager->registerResources([
    '/css/critical.css' => 'style',
    '/js/vendor.js' => 'script',
    '/images/hero.webp' => [
        'type' => 'image',
        'priority' => 16,
    ],
]);
```

### 推送规则

```php
// 特定路径推送
$pushManager->addPushRule('/dashboard', [
    '/css/dashboard.css' => 'style',
    '/js/dashboard.js' => 'script',
]);

// 模式匹配
$pushManager->addPushRule('/admin/*', [
    '/css/admin.css' => 'style',
]);

// 全局推送
$pushManager->addPushRule('/*', [
    '/css/common.css' => 'style',
]);
```

### 资源优先级

| 类型 | 默认优先级 | 说明 |
|------|-----------|------|
| `style` | 32 | CSS 样式表 |
| `document` | 32 | HTML 文档 |
| `script` | 24 | JavaScript |
| `font` | 20 | 字体文件 |
| `image` | 8 | 图片 |
| `fetch` | 16 | 其他资源 |

## 多路复用

HTTP/2 多路复用允许单个 TCP 连接上并发多个请求/响应。

### MultiplexingManager

```php
use HybridPHP\Core\Server\Http2\MultiplexingManager;
use HybridPHP\Core\Server\Http2\StreamManager;
use HybridPHP\Core\Server\Http2\Http2Config;

$config = new Http2Config([
    'max_concurrent_streams' => 100,
    'initial_window_size' => 65535,
]);

$streamManager = new StreamManager($config);
$multiplexing = new MultiplexingManager($streamManager, $config);

// 创建带优先级的流
$stream = $multiplexing->createStream(null, 32);

// 提交流处理
$future = $multiplexing->submitStream($stream->getId(), function($stream, $manager) {
    $data = $stream->getBody();
    // 处理请求...
    return $response;
});

$result = $future->await();
```

### 流优先级

```php
// 更新流优先级
$multiplexing->updatePriority(
    $streamId,
    weight: 64,        // 1-256，越高带宽越多
    dependency: 0,     // 父流 ID
    exclusive: false   // 是否独占
);

// 获取按优先级排序的活动流
$streams = $multiplexing->getActiveStreamsByPriority();
```

### 流控制

```php
use HybridPHP\Core\Server\Http2\FlowController;

$flowController = new FlowController(65535);

// 初始化流控制
$flowController->initStream($streamId);

// 请求发送数据
$allowed = $flowController->requestSend($streamId, $dataSize)->await();

// 消费窗口
$flowController->consumeSendWindow($streamId, $bytesSent);

// 处理 WINDOW_UPDATE
$flowController->processWindowUpdate($streamId, $increment);
```

## HPACK 头部压缩

### 基础使用

```php
use HybridPHP\Core\Server\Http2\HpackContext;

$hpack = new HpackContext(4096, true); // 4KB 表，启用 Huffman

// 压缩头部
$compressed = $hpack->compress([
    ':method' => 'GET',
    ':path' => '/api/users',
    ':scheme' => 'https',
    ':authority' => 'example.com',
    'accept' => 'application/json',
]);

// 解压头部
$headers = $hpack->decompress($compressed);
```

### 压缩统计

```php
$stats = $hpack->getStats();
// [
//     'headers_encoded' => 100,
//     'headers_decoded' => 95,
//     'bytes_before_compression' => 50000,
//     'bytes_after_compression' => 15000,
//     'compression_ratio' => 0.7,
// ]
```

## 配置选项

### 服务器设置

| 选项 | 默认值 | 说明 |
|------|-------|------|
| `host` | `0.0.0.0` | 绑定地址 |
| `port` | `8443` | 端口 |
| `enable_http2` | `true` | 启用 HTTP/2 |
| `connection_timeout` | `30` | 连接超时(秒) |
| `body_size_limit` | `128MB` | 最大请求体 |

### HTTP/2 协议设置

| 选项 | 默认值 | 说明 |
|------|-------|------|
| `max_concurrent_streams` | `100` | 最大并发流 |
| `initial_window_size` | `65535` | 流控窗口大小 |
| `max_frame_size` | `16384` | 最大帧大小 |
| `header_table_size` | `4096` | HPACK 表大小 |

### TLS 设置

| 选项 | 默认值 | 说明 |
|------|-------|------|
| `cert_path` | - | 证书路径 |
| `key_path` | - | 私钥路径 |
| `min_tls_version` | `TLSv1.2` | 最低 TLS 版本 |
| `max_tls_version` | `TLSv1.3` | 最高 TLS 版本 |

## 监控统计

```php
$stats = $server->getStats();
// [
//     'requests' => 1000,
//     'http2_connections' => 50,
//     'server_pushes' => 200,
//     'streams_opened' => 5000,
//     'uptime' => 3600,
// ]

$health = $server->checkHealth();
// [
//     'status' => 'healthy',
//     'protocol' => 'HTTP/2',
//     'connections' => 50,
//     'memory_usage' => 52428800,
// ]
```

## 生产部署

### Nginx 反向代理

```nginx
upstream hybridphp {
    server 127.0.0.1:8443;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;
    
    ssl_certificate /etc/ssl/certs/your-domain.crt;
    ssl_certificate_key /etc/ssl/private/your-domain.key;
    
    location / {
        proxy_pass https://hybridphp;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

### 安全头部

```php
'security_headers' => [
    'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload',
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'DENY',
    'X-XSS-Protection' => '1; mode=block',
],
```

## 下一步

- [WebSocket](./WEBSOCKET.md) - 实时通信
- [gRPC 服务](./GRPC.md) - 微服务通信
- [IM 系统实战](../applications/IM_SYSTEM.md) - 高并发应用
