# IM 即时通讯系统实战

本文档介绍如何使用 HybridPHP 构建高并发的即时通讯（IM）系统，涵盖架构设计、核心功能实现和性能优化。

## 系统架构

### 整体架构

```
┌─────────────────────────────────────────────────────────────────┐
│                        客户端层                                  │
│     Web App    │    iOS App    │   Android App   │   Desktop    │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                        接入层                                    │
│   ┌─────────────┐   ┌─────────────┐   ┌─────────────┐          │
│   │   Nginx     │   │   Nginx     │   │   Nginx     │          │
│   │   (LB)      │   │   (LB)      │   │   (LB)      │          │
│   └──────┬──────┘   └──────┬──────┘   └──────┬──────┘          │
└──────────┼─────────────────┼─────────────────┼──────────────────┘
           │                 │                 │
           ▼                 ▼                 ▼
┌─────────────────────────────────────────────────────────────────┐
│                      WebSocket 网关层                            │
│   ┌─────────────┐   ┌─────────────┐   ┌─────────────┐          │
│   │  Gateway 1  │   │  Gateway 2  │   │  Gateway 3  │          │
│   │  (HybridPHP)│   │  (HybridPHP)│   │  (HybridPHP)│          │
│   └──────┬──────┘   └──────┬──────┘   └──────┬──────┘          │
└──────────┼─────────────────┼─────────────────┼──────────────────┘
           │                 │                 │
           └─────────────────┼─────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│                        业务服务层                                │
│   ┌─────────────┐   ┌─────────────┐   ┌─────────────┐          │
│   │ 消息服务    │   │ 用户服务    │   │ 群组服务    │          │
│   │ (gRPC)      │   │ (gRPC)      │   │ (gRPC)      │          │
│   └─────────────┘   └─────────────┘   └─────────────┘          │
└─────────────────────────────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│                        数据存储层                                │
│   ┌─────────────┐   ┌─────────────┐   ┌─────────────┐          │
│   │   MySQL     │   │   Redis     │   │   MongoDB   │          │
│   │  (用户/群组) │   │  (会话/在线) │   │  (消息存储) │          │
│   └─────────────┘   └─────────────┘   └─────────────┘          │
└─────────────────────────────────────────────────────────────────┘
```

### 技术选型

| 组件 | 技术 | 说明 |
|------|------|------|
| 长连接 | WebSocket | 实时双向通信 |
| 服务通信 | gRPC | 高性能 RPC |
| 消息队列 | Redis Pub/Sub | 跨网关消息分发 |
| 会话存储 | Redis | 在线状态、会话信息 |
| 消息存储 | MongoDB | 海量消息存储 |
| 用户数据 | MySQL | 用户、群组关系 |
| 追踪 | Jaeger | 分布式追踪 |

## 核心功能实现

### 1. WebSocket 网关

```php
<?php
// app/Gateway/IMGateway.php

namespace App\Gateway;

use HybridPHP\Core\Server\WebSocket\EnhancedWebSocketServer;
use HybridPHP\Core\Server\WebSocket\Connection;
use HybridPHP\Core\EventEmitter;

class IMGateway
{
    private EnhancedWebSocketServer $server;
    private EventEmitter $eventEmitter;
    private RedisClient $redis;
    private MessageService $messageService;
    
    public function __construct(array $config)
    {
        $this->eventEmitter = new EventEmitter();
        $this->server = new EnhancedWebSocketServer($config);
        $this->server->setEventEmitter($this->eventEmitter);
        
        $this->registerHandlers();
        $this->registerEvents();
    }
    
    private function registerHandlers(): void
    {
        // 发送私聊消息
        $this->server->on('chat.private', function (Connection $conn, array $msg) {
            return $this->handlePrivateMessage($conn, $msg);
        });
        
        // 发送群聊消息
        $this->server->on('chat.group', function (Connection $conn, array $msg) {
            return $this->handleGroupMessage($conn, $msg);
        });
        
        // 消息已读回执
        $this->server->on('message.read', function (Connection $conn, array $msg) {
            return $this->handleMessageRead($conn, $msg);
        });
        
        // 正在输入状态
        $this->server->on('typing', function (Connection $conn, array $msg) {
            return $this->handleTyping($conn, $msg);
        });
    }
    
    private function registerEvents(): void
    {
        // 用户连接
        $this->eventEmitter->on('websocket.connect', function (Connection $conn) {
            $this->onUserConnect($conn);
        });
        
        // 用户断开
        $this->eventEmitter->on('websocket.disconnect', function ($conn, $reason, $token) {
            $this->onUserDisconnect($conn, $reason, $token);
        });
    }
    
    private function onUserConnect(Connection $conn): void
    {
        // 验证 Token
        $token = $conn->getRequest()->getHeaderLine('Authorization');
        $user = $this->authService->validateToken($token);
        
        if (!$user) {
            $conn->close(4001, 'Unauthorized');
            return;
        }
        
        // 绑定用户信息
        $conn->setAttribute('user_id', $user->id);
        $conn->setAttribute('user_name', $user->name);
        
        // 注册到 Redis（支持多网关）
        $this->redis->hSet(
            'im:online:users',
            $user->id,
            json_encode([
                'gateway' => $this->gatewayId,
                'connection_id' => $conn->getId(),
                'connected_at' => time(),
            ])
        );
        
        // 发送离线消息
        $this->sendOfflineMessages($conn, $user->id);
        
        // 通知好友上线
        $this->notifyFriendsOnline($user->id);
    }
    
    private function onUserDisconnect(Connection $conn, string $reason, string $token): void
    {
        $userId = $conn->getAttribute('user_id');
        
        if ($userId) {
            // 从 Redis 移除
            $this->redis->hDel('im:online:users', $userId);
            
            // 通知好友下线
            $this->notifyFriendsOffline($userId);
        }
    }
    
    private function handlePrivateMessage(Connection $conn, array $msg): array
    {
        $fromUserId = $conn->getAttribute('user_id');
        $toUserId = $msg['to_user_id'];
        $content = $msg['content'];
        $type = $msg['type'] ?? 'text';
        
        // 创建消息
        $message = $this->messageService->createMessage([
            'from_user_id' => $fromUserId,
            'to_user_id' => $toUserId,
            'content' => $content,
            'type' => $type,
            'conversation_type' => 'private',
        ]);
        
        // 发送给接收者
        $this->deliverMessage($toUserId, [
            'type' => 'chat.private',
            'message' => $message->toArray(),
        ]);
        
        return [
            'type' => 'message.sent',
            'message_id' => $message->id,
            'timestamp' => $message->created_at,
        ];
    }
    
    private function handleGroupMessage(Connection $conn, array $msg): array
    {
        $fromUserId = $conn->getAttribute('user_id');
        $groupId = $msg['group_id'];
        $content = $msg['content'];
        $type = $msg['type'] ?? 'text';
        
        // 检查群组权限
        if (!$this->groupService->isMember($groupId, $fromUserId)) {
            return ['type' => 'error', 'message' => 'Not a group member'];
        }
        
        // 创建消息
        $message = $this->messageService->createMessage([
            'from_user_id' => $fromUserId,
            'group_id' => $groupId,
            'content' => $content,
            'type' => $type,
            'conversation_type' => 'group',
        ]);
        
        // 广播给群成员
        $members = $this->groupService->getMembers($groupId);
        foreach ($members as $memberId) {
            if ($memberId !== $fromUserId) {
                $this->deliverMessage($memberId, [
                    'type' => 'chat.group',
                    'message' => $message->toArray(),
                ]);
            }
        }
        
        return [
            'type' => 'message.sent',
            'message_id' => $message->id,
            'timestamp' => $message->created_at,
        ];
    }
    
    private function deliverMessage(int $userId, array $data): void
    {
        // 查找用户所在网关
        $userInfo = $this->redis->hGet('im:online:users', $userId);
        
        if (!$userInfo) {
            // 用户离线，存储离线消息
            $this->storeOfflineMessage($userId, $data);
            return;
        }
        
        $userInfo = json_decode($userInfo, true);
        
        if ($userInfo['gateway'] === $this->gatewayId) {
            // 用户在当前网关，直接发送
            $this->server->sendTo($userInfo['connection_id'], $data);
        } else {
            // 用户在其他网关，通过 Redis Pub/Sub 转发
            $this->redis->publish('im:message:' . $userInfo['gateway'], json_encode([
                'connection_id' => $userInfo['connection_id'],
                'data' => $data,
            ]));
        }
    }
}
```

### 2. 消息服务 (gRPC)

```php
<?php
// app/Services/MessageService.php

namespace App\Services;

use HybridPHP\Core\Grpc\ServiceInterface;
use HybridPHP\Core\Grpc\Context;
use HybridPHP\Core\Grpc\MethodType;

class MessageService implements ServiceInterface
{
    private MongoDB $mongodb;
    private RedisClient $redis;
    private Tracer $tracer;
    
    public function getServiceName(): string
    {
        return 'im.MessageService';
    }
    
    public function getMethods(): array
    {
        return [
            'SendMessage' => [
                'type' => MethodType::UNARY,
                'requestClass' => SendMessageRequest::class,
                'responseClass' => SendMessageResponse::class,
            ],
            'GetMessages' => [
                'type' => MethodType::UNARY,
                'requestClass' => GetMessagesRequest::class,
                'responseClass' => GetMessagesResponse::class,
            ],
            'SyncMessages' => [
                'type' => MethodType::SERVER_STREAMING,
                'requestClass' => SyncMessagesRequest::class,
                'responseClass' => Message::class,
            ],
        ];
    }
    
    public function SendMessage(SendMessageRequest $request, Context $context): SendMessageResponse
    {
        $span = $this->tracer->startSpan('message.send', [
            'from_user_id' => $request->getFromUserId(),
            'conversation_type' => $request->getConversationType(),
        ]);
        
        try {
            // 生成消息ID（雪花算法）
            $messageId = $this->generateMessageId();
            
            // 构建消息
            $message = [
                '_id' => $messageId,
                'from_user_id' => $request->getFromUserId(),
                'to_user_id' => $request->getToUserId(),
                'group_id' => $request->getGroupId(),
                'content' => $request->getContent(),
                'type' => $request->getType(),
                'conversation_type' => $request->getConversationType(),
                'created_at' => new \MongoDB\BSON\UTCDateTime(),
                'status' => 'sent',
            ];
            
            // 存储消息
            $this->mongodb->messages->insertOne($message);
            
            // 更新会话
            $this->updateConversation($message);
            
            $span->setStatus(SpanStatus::OK);
            
            $response = new SendMessageResponse();
            $response->setMessageId($messageId);
            $response->setTimestamp(time());
            return $response;
            
        } catch (\Throwable $e) {
            $span->recordException($e);
            throw $e;
        } finally {
            $span->end();
        }
    }
    
    public function GetMessages(GetMessagesRequest $request, Context $context): GetMessagesResponse
    {
        $conversationId = $request->getConversationId();
        $lastMessageId = $request->getLastMessageId();
        $limit = $request->getLimit() ?: 20;
        
        $query = ['conversation_id' => $conversationId];
        
        if ($lastMessageId) {
            $query['_id'] = ['$lt' => $lastMessageId];
        }
        
        $messages = $this->mongodb->messages
            ->find($query, [
                'sort' => ['_id' => -1],
                'limit' => $limit,
            ])
            ->toArray();
        
        $response = new GetMessagesResponse();
        foreach ($messages as $msg) {
            $response->addMessage($this->toProtoMessage($msg));
        }
        
        return $response;
    }
    
    public function SyncMessages(SyncMessagesRequest $request, Context $context): \Generator
    {
        $userId = $request->getUserId();
        $lastSyncTime = $request->getLastSyncTime();
        
        // 查询需要同步的消息
        $messages = $this->mongodb->messages->find([
            '$or' => [
                ['to_user_id' => $userId],
                ['from_user_id' => $userId],
            ],
            'created_at' => ['$gt' => new \MongoDB\BSON\UTCDateTime($lastSyncTime * 1000)],
        ], [
            'sort' => ['created_at' => 1],
        ]);
        
        foreach ($messages as $msg) {
            yield $this->toProtoMessage($msg);
        }
    }
    
    private function generateMessageId(): string
    {
        // 雪花算法生成分布式唯一ID
        return $this->snowflake->nextId();
    }
    
    private function updateConversation(array $message): void
    {
        $conversationId = $this->getConversationId($message);
        
        $this->redis->hSet(
            'im:conversations:' . $message['from_user_id'],
            $conversationId,
            json_encode([
                'last_message' => $message['content'],
                'last_message_time' => time(),
                'unread_count' => 0,
            ])
        );
        
        // 接收者未读数+1
        if ($message['conversation_type'] === 'private') {
            $this->redis->hIncrBy(
                'im:conversations:' . $message['to_user_id'],
                $conversationId . ':unread',
                1
            );
        }
    }
}
```

### 3. 在线状态管理

```php
<?php
// app/Services/PresenceService.php

namespace App\Services;

class PresenceService
{
    private RedisClient $redis;
    private const ONLINE_KEY = 'im:online:users';
    private const HEARTBEAT_KEY = 'im:heartbeat:';
    private const HEARTBEAT_TIMEOUT = 60;
    
    public function setOnline(int $userId, array $connectionInfo): void
    {
        $this->redis->hSet(self::ONLINE_KEY, $userId, json_encode($connectionInfo));
        $this->redis->setEx(self::HEARTBEAT_KEY . $userId, self::HEARTBEAT_TIMEOUT, time());
    }
    
    public function setOffline(int $userId): void
    {
        $this->redis->hDel(self::ONLINE_KEY, $userId);
        $this->redis->del(self::HEARTBEAT_KEY . $userId);
    }
    
    public function isOnline(int $userId): bool
    {
        return $this->redis->hExists(self::ONLINE_KEY, $userId);
    }
    
    public function getOnlineUsers(array $userIds): array
    {
        $result = [];
        $onlineData = $this->redis->hMGet(self::ONLINE_KEY, $userIds);
        
        foreach ($userIds as $index => $userId) {
            $result[$userId] = $onlineData[$index] !== false;
        }
        
        return $result;
    }
    
    public function heartbeat(int $userId): void
    {
        $this->redis->setEx(self::HEARTBEAT_KEY . $userId, self::HEARTBEAT_TIMEOUT, time());
    }
    
    public function cleanupDeadConnections(): void
    {
        $onlineUsers = $this->redis->hGetAll(self::ONLINE_KEY);
        
        foreach ($onlineUsers as $userId => $info) {
            if (!$this->redis->exists(self::HEARTBEAT_KEY . $userId)) {
                $this->setOffline($userId);
            }
        }
    }
}
```

### 4. 消息推送优化

```php
<?php
// app/Services/PushService.php

namespace App\Services;

class PushService
{
    private RedisClient $redis;
    private array $gateways = [];
    
    public function __construct()
    {
        // 订阅消息转发频道
        $this->subscribeMessageChannel();
    }
    
    private function subscribeMessageChannel(): void
    {
        $gatewayId = $this->getGatewayId();
        
        $this->redis->subscribe(['im:message:' . $gatewayId], function ($channel, $message) {
            $data = json_decode($message, true);
            $this->deliverToConnection($data['connection_id'], $data['data']);
        });
    }
    
    public function pushToUser(int $userId, array $data): bool
    {
        $userInfo = $this->redis->hGet('im:online:users', $userId);
        
        if (!$userInfo) {
            return false;
        }
        
        $userInfo = json_decode($userInfo, true);
        
        // 发布到目标网关
        $this->redis->publish(
            'im:message:' . $userInfo['gateway'],
            json_encode([
                'connection_id' => $userInfo['connection_id'],
                'data' => $data,
            ])
        );
        
        return true;
    }
    
    public function pushToUsers(array $userIds, array $data): array
    {
        $results = [];
        
        // 批量获取用户在线信息
        $onlineInfo = $this->redis->hMGet('im:online:users', $userIds);
        
        // 按网关分组
        $gatewayMessages = [];
        foreach ($userIds as $index => $userId) {
            if ($onlineInfo[$index]) {
                $info = json_decode($onlineInfo[$index], true);
                $gatewayMessages[$info['gateway']][] = [
                    'connection_id' => $info['connection_id'],
                    'data' => $data,
                ];
                $results[$userId] = true;
            } else {
                $results[$userId] = false;
            }
        }
        
        // 批量发布到各网关
        foreach ($gatewayMessages as $gateway => $messages) {
            $this->redis->publish(
                'im:message:' . $gateway,
                json_encode(['batch' => $messages])
            );
        }
        
        return $results;
    }
}
```

## 分布式追踪集成

### 消息链路追踪

```php
<?php
// 在消息发送时创建追踪

class MessageTracer
{
    private Tracer $tracer;
    
    public function traceMessageFlow(array $message): void
    {
        // 创建根 Span
        $rootSpan = $this->tracer->startTrace('im.message.flow', [
            'message_id' => $message['id'],
            'from_user_id' => $message['from_user_id'],
            'conversation_type' => $message['conversation_type'],
        ]);
        
        try {
            // 消息验证
            $validateSpan = $this->tracer->startSpan('message.validate');
            $this->validateMessage($message);
            $validateSpan->end();
            
            // 消息存储
            $storeSpan = $this->tracer->startSpan('message.store');
            $storeSpan->setAttribute('db.system', 'mongodb');
            $this->storeMessage($message);
            $storeSpan->end();
            
            // 消息投递
            $deliverSpan = $this->tracer->startSpan('message.deliver');
            $deliverSpan->setAttribute('recipients_count', count($recipients));
            $this->deliverMessage($message);
            $deliverSpan->end();
            
            $rootSpan->setStatus(SpanStatus::OK);
        } catch (\Throwable $e) {
            $rootSpan->recordException($e);
            throw $e;
        } finally {
            $rootSpan->end();
            $this->tracer->flush();
        }
    }
}
```

### 追踪可视化

在 Jaeger UI 中查看消息链路：

```
im.message.flow ─────────────────────────────────────────────────
    │
    ├── message.validate ────────
    │
    ├── message.store (MongoDB) ──────────────
    │
    └── message.deliver ─────────────────────────────────────────
            │
            ├── push.user.123 ────────
            │
            └── push.user.456 ────────
```

## 性能优化

### 1. 连接池优化

```php
// 数据库连接池
$pool = new ConnectionPool([
    'min_connections' => 10,
    'max_connections' => 100,
    'idle_timeout' => 60,
]);

// Redis 连接池
$redisPool = new RedisPool([
    'min_connections' => 5,
    'max_connections' => 50,
]);
```

### 2. 消息批量处理

```php
class MessageBatcher
{
    private array $buffer = [];
    private int $batchSize = 100;
    private float $flushInterval = 0.1; // 100ms
    
    public function add(array $message): void
    {
        $this->buffer[] = $message;
        
        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }
    
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }
        
        // 批量写入 MongoDB
        $this->mongodb->messages->insertMany($this->buffer);
        $this->buffer = [];
    }
}
```

### 3. 消息压缩

```php
class MessageCompressor
{
    public function compress(array $message): string
    {
        $json = json_encode($message);
        return gzcompress($json, 6);
    }
    
    public function decompress(string $data): array
    {
        $json = gzuncompress($data);
        return json_decode($json, true);
    }
}
```

## 高可用部署

### Docker Compose 配置

```yaml
version: '3.8'

services:
  im-gateway-1:
    image: hybridphp/im-gateway
    environment:
      - GATEWAY_ID=gateway-1
      - REDIS_HOST=redis
      - MONGODB_HOST=mongodb
    ports:
      - "9091:9090"
    depends_on:
      - redis
      - mongodb

  im-gateway-2:
    image: hybridphp/im-gateway
    environment:
      - GATEWAY_ID=gateway-2
      - REDIS_HOST=redis
      - MONGODB_HOST=mongodb
    ports:
      - "9092:9090"
    depends_on:
      - redis
      - mongodb

  message-service:
    image: hybridphp/message-service
    environment:
      - MONGODB_HOST=mongodb
    ports:
      - "50051:50051"

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  mongodb:
    image: mongo:6
    ports:
      - "27017:27017"

  jaeger:
    image: jaegertracing/all-in-one:latest
    ports:
      - "16686:16686"
      - "14268:14268"
```

### Kubernetes 部署

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: im-gateway
spec:
  replicas: 3
  selector:
    matchLabels:
      app: im-gateway
  template:
    metadata:
      labels:
        app: im-gateway
    spec:
      containers:
      - name: gateway
        image: hybridphp/im-gateway:latest
        ports:
        - containerPort: 9090
        env:
        - name: GATEWAY_ID
          valueFrom:
            fieldRef:
              fieldPath: metadata.name
        resources:
          requests:
            memory: "256Mi"
            cpu: "250m"
          limits:
            memory: "512Mi"
            cpu: "500m"
---
apiVersion: v1
kind: Service
metadata:
  name: im-gateway
spec:
  selector:
    app: im-gateway
  ports:
  - port: 9090
    targetPort: 9090
  type: LoadBalancer
```

## 监控指标

### Prometheus 指标

```php
// 连接数
$gauge->set('im_connections_total', $connectionCount);

// 消息发送量
$counter->inc('im_messages_sent_total', ['type' => 'private']);

// 消息延迟
$histogram->observe('im_message_latency_seconds', $latency);

// 在线用户数
$gauge->set('im_online_users_total', $onlineCount);
```

### Grafana 仪表板

关键监控指标：
- 在线用户数趋势
- 消息发送/接收 QPS
- 消息延迟分布
- 连接建立/断开率
- 各网关负载分布

## 下一步

- [实时推送系统](./REALTIME_PUSH.md) - 消息推送实现
- [微服务架构](./MICROSERVICES.md) - 分布式系统设计
- [分布式追踪](../advanced/TRACING.md) - 追踪系统详解
