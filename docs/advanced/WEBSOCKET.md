# WebSocket 实时通信

HybridPHP 提供了功能完善的 WebSocket 服务器，支持房间管理、消息广播、心跳检测和断线重连等高级特性。

## 核心特性

- **房间/频道管理**: 支持用户加入/离开房间，房间内广播
- **消息广播**: 支持全局广播、房间广播、点对点消息
- **心跳检测**: 自动检测连接状态，清理死连接
- **断线重连**: 支持会话恢复，保持用户状态
- **事件驱动**: 完整的事件系统，易于扩展

## 快速开始

### 基础配置

```php
// config/websocket.php
return [
    'host' => '0.0.0.0',
    'port' => 9090,
    'processes' => 4,
    'heartbeat_interval' => 30,    // 心跳间隔(秒)
    'heartbeat_timeout' => 60,     // 心跳超时(秒)
    'reconnection_ttl' => 300,     // 重连有效期(秒)
    'reconnection_max_attempts' => 5,
    'max_connections_per_room' => 100,
    'max_rooms_per_connection' => 10,
];
```

### 创建 WebSocket 服务器

```php
use HybridPHP\Core\Server\WebSocket\EnhancedWebSocketServer;
use HybridPHP\Core\Server\WebSocket\Connection;
use HybridPHP\Core\EventEmitter;

$eventEmitter = new EventEmitter();

$server = new EnhancedWebSocketServer([
    'host' => '0.0.0.0',
    'port' => 9090,
    'heartbeat_interval' => 30,
    'heartbeat_timeout' => 60,
]);

$server->setEventEmitter($eventEmitter);
```

## 消息处理

### 注册消息处理器

```php
// 聊天消息处理
$server->on('chat', function (Connection $conn, array $message, $server) {
    $room = $message['room'] ?? 'general';
    $text = $message['text'] ?? '';
    
    // 广播到房间
    $server->broadcast($room, [
        'type' => 'chat',
        'from' => $conn->getId(),
        'room' => $room,
        'text' => $text,
        'timestamp' => time(),
    ]);
    
    return ['type' => 'chat_sent', 'room' => $room];
});

// 私聊消息处理
$server->on('private', function (Connection $conn, array $message, $server) {
    $targetId = $message['to'] ?? null;
    $text = $message['text'] ?? '';
    
    if (!$targetId) {
        return ['type' => 'error', 'message' => 'Target required'];
    }
    
    $sent = $server->sendTo($targetId, [
        'type' => 'private',
        'from' => $conn->getId(),
        'text' => $text,
        'timestamp' => time(),
    ]);
    
    return ['type' => 'private_sent', 'delivered' => $sent];
});
```

### 内置消息类型

| 类型 | 说明 | 示例 |
|------|------|------|
| `join` | 加入房间 | `{"type":"join","room":"room1"}` |
| `leave` | 离开房间 | `{"type":"leave","room":"room1"}` |
| `broadcast` | 房间广播 | `{"type":"broadcast","room":"room1","data":"hello"}` |
| `ping` | 心跳检测 | `{"type":"ping"}` |
| `reconnect` | 断线重连 | `{"type":"reconnect","token":"xxx"}` |

## 房间管理

### 房间操作

```php
$roomManager = $server->getRoomManager();

// 创建房间
$roomManager->createRoom('game_room_1', [
    'max_members' => 50,
    'metadata' => ['game_type' => 'chess']
]);

// 加入房间
$roomManager->joinRoom($connection, 'game_room_1');

// 离开房间
$roomManager->leaveRoom($connection, 'game_room_1');

// 获取房间成员
$members = $roomManager->getRoomMembers('game_room_1');

// 获取房间统计
$stats = $roomManager->getRoomStats('game_room_1');
// ['connections' => 10, 'created_at' => 1234567890]

// 获取所有房间
$rooms = $roomManager->getRooms();
```

### 房间广播

```php
// 广播到指定房间
$server->broadcast('game_room_1', [
    'type' => 'game_update',
    'data' => $gameState
]);

// 广播到所有房间
$server->broadcastAll([
    'type' => 'system_notice',
    'message' => 'Server maintenance in 5 minutes'
]);

// 排除某些连接的广播
$server->broadcast('room1', $message, [$excludeConnectionId]);
```

## 心跳检测

### 工作原理

```
Client                    Server
   │                         │
   │──── ping ──────────────▶│
   │                         │
   │◀─── pong ──────────────│
   │                         │
   │    (30秒后)             │
   │──── ping ──────────────▶│
   │                         │
```

### 配置心跳

```php
$server = new EnhancedWebSocketServer([
    'heartbeat_interval' => 30,  // 每30秒发送心跳
    'heartbeat_timeout' => 60,   // 60秒无响应断开
]);

// 客户端响应心跳
// 收到 {"type":"ping"} 后回复 {"type":"pong"}
```

### 心跳管理器

```php
$heartbeatManager = $server->getHeartbeatManager();

// 获取连接最后活动时间
$lastActivity = $heartbeatManager->getLastActivity($connectionId);

// 手动更新活动时间
$heartbeatManager->updateActivity($connectionId);

// 检查连接是否存活
$isAlive = $heartbeatManager->isAlive($connectionId);
```

## 断线重连

### 重连机制

```
1. 客户端断开连接
2. 服务器生成重连令牌，保存会话状态
3. 客户端在有效期内使用令牌重连
4. 服务器恢复会话状态（房间、用户数据等）
```

### 服务端配置

```php
$server = new EnhancedWebSocketServer([
    'reconnection_ttl' => 300,           // 重连有效期5分钟
    'reconnection_max_attempts' => 5,    // 最大重连次数
]);

// 监听断开事件，获取重连令牌
$eventEmitter->on('websocket.disconnect', function ($conn, $reason, $token) {
    // $token 可发送给客户端用于重连
    echo "Reconnection token: {$token}\n";
});

// 监听重连成功事件
$eventEmitter->on('websocket.reconnect', function ($conn, $session) {
    echo "Reconnected: {$session['previous_connection_id']}\n";
});
```

### 客户端重连

```javascript
// 保存重连令牌
let reconnectToken = null;

ws.onclose = function(event) {
    // 尝试重连
    setTimeout(() => {
        ws = new WebSocket('ws://localhost:9090');
        ws.onopen = function() {
            if (reconnectToken) {
                ws.send(JSON.stringify({
                    type: 'reconnect',
                    token: reconnectToken
                }));
            }
        };
    }, 1000);
};

ws.onmessage = function(event) {
    const data = JSON.parse(event.data);
    if (data.type === 'reconnect_token') {
        reconnectToken = data.token;
    }
};
```

## 事件系统

### 可用事件

```php
// 服务器启动
$eventEmitter->on('websocket.started', function ($server) {
    echo "WebSocket Server Started!\n";
});

// 新连接
$eventEmitter->on('websocket.connect', function (Connection $conn) {
    echo "New connection: {$conn->getId()}\n";
});

// 连接断开
$eventEmitter->on('websocket.disconnect', function ($conn, $reason, $token) {
    echo "Disconnected: {$conn->getId()}\n";
});

// 加入房间
$eventEmitter->on('websocket.room.join', function ($conn, $room) {
    echo "{$conn->getId()} joined {$room}\n";
});

// 离开房间
$eventEmitter->on('websocket.room.leave', function ($conn, $room) {
    echo "{$conn->getId()} left {$room}\n";
});

// 重连成功
$eventEmitter->on('websocket.reconnect', function ($conn, $session) {
    echo "Reconnected: {$conn->getId()}\n";
});

// 消息接收
$eventEmitter->on('websocket.message', function ($conn, $message) {
    echo "Message from {$conn->getId()}\n";
});
```

## 连接管理

### Connection 对象

```php
// 获取连接ID
$id = $connection->getId();

// 获取连接属性
$userId = $connection->getAttribute('user_id');

// 设置连接属性
$connection->setAttribute('user_id', 123);

// 发送消息
$connection->send(json_encode(['type' => 'hello']));

// 关闭连接
$connection->close();

// 获取连接信息
$info = $connection->getInfo();
// ['id' => 'xxx', 'remote_address' => '127.0.0.1', 'connected_at' => 1234567890]
```

### 连接查询

```php
// 获取所有连接
$connections = $server->getConnections();

// 根据ID获取连接
$connection = $server->getConnection($connectionId);

// 获取连接数量
$count = $server->getConnectionCount();

// 根据属性查找连接
$userConnections = $server->findConnections(function ($conn) {
    return $conn->getAttribute('user_id') === 123;
});
```

## 服务器统计

```php
$stats = $server->getStats();

// 返回:
// [
//     'connections' => 100,        // 当前连接数
//     'rooms' => 10,               // 房间数量
//     'messages_sent' => 5000,     // 发送消息数
//     'messages_received' => 4500, // 接收消息数
//     'uptime' => 3600,            // 运行时间(秒)
//     'memory_usage' => 52428800,  // 内存使用(字节)
// ]
```

## 安全配置

### 认证中间件

```php
$server->on('connect', function (Connection $conn, $request) {
    // 验证 Token
    $token = $request->getHeaderLine('Authorization');
    
    if (!$this->validateToken($token)) {
        $conn->close(4001, 'Unauthorized');
        return false;
    }
    
    // 设置用户信息
    $user = $this->getUserFromToken($token);
    $conn->setAttribute('user_id', $user->id);
    $conn->setAttribute('user_name', $user->name);
    
    return true;
});
```

### 消息验证

```php
$server->on('chat', function (Connection $conn, array $message, $server) {
    // 验证消息格式
    if (empty($message['text']) || strlen($message['text']) > 1000) {
        return ['type' => 'error', 'message' => 'Invalid message'];
    }
    
    // 检查发送频率
    $lastSent = $conn->getAttribute('last_message_time') ?? 0;
    if (time() - $lastSent < 1) {
        return ['type' => 'error', 'message' => 'Rate limited'];
    }
    
    $conn->setAttribute('last_message_time', time());
    
    // 处理消息...
});
```

## 生产部署

### Nginx 代理配置

```nginx
upstream websocket {
    server 127.0.0.1:9090;
}

server {
    listen 443 ssl http2;
    server_name ws.example.com;
    
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;
    
    location / {
        proxy_pass http://websocket;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_read_timeout 86400;
    }
}
```

### Supervisor 配置

```ini
[program:websocket]
command=/usr/bin/php /var/www/app/websocket_server.php
directory=/var/www/app
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/websocket.log
```

## 下一步

- [IM 即时通讯系统](../applications/IM_SYSTEM.md) - 基于 WebSocket 构建 IM 系统
- [实时推送系统](../applications/REALTIME_PUSH.md) - 消息推送实现
- [gRPC 服务](./GRPC.md) - 微服务 RPC 通信
