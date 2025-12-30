# 缓存系统

HybridPHP 提供高性能、分布式的异步缓存系统，支持多级缓存和防缓存穿透/雪崩/击穿保护。

## 核心特性

- **多级缓存**: L1 (内存) + L2 (Redis/文件) 组合
- **分布式缓存**: 一致性哈希支持 Redis 集群
- **防护机制**: 缓存穿透、雪崩、击穿保护
- **异步操作**: 所有操作返回 Promise，非阻塞 I/O
- **标签缓存**: 支持按标签批量失效

## 快速开始

### 配置

```php
// config/cache.php
return [
    'default' => 'multilevel',
    
    'stores' => [
        'multilevel' => [
            'driver' => 'multilevel',
            'l1' => ['driver' => 'memory', 'max_size' => 50 * 1024 * 1024],
            'l2' => ['driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379],
        ],
        
        'redis' => [
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'port' => 6379,
        ],
    ],
    
    'protection' => [
        'stampede' => ['enabled' => true, 'lock_timeout' => 30],
        'penetration' => ['enabled' => true, 'null_ttl' => 300],
        'avalanche' => ['enabled' => true, 'jitter_range' => 0.1],
    ],
];
```

### 基础操作

```php
use HybridPHP\Core\Cache\CacheManager;

$cache = $container->get(CacheManager::class);

// 设置缓存
$cache->store()->set('user:123', $userData, 3600)->await();

// 获取缓存
$user = $cache->store()->get('user:123')->await();

// 检查存在
$exists = $cache->store()->has('user:123')->await();

// 删除缓存
$cache->store()->delete('user:123')->await();

// 批量操作
$users = $cache->store()->getMultiple(['user:123', 'user:456'])->await();
$cache->store()->setMultiple(['user:123' => $data1, 'user:456' => $data2])->await();
```

### 防击穿保护

```php
// remember 模式：只有一个请求会执行回调
$data = $cache->remember('expensive_key', function () {
    return performExpensiveComputation();
}, 3600)->await();
```

### 防穿透保护

```php
// 缓存空值，防止重复查询不存在的数据
$data = $cache->rememberWithNullProtection('user:999', function () {
    return $database->findUser(999); // 可能返回 null
}, 3600)->await();
```

### 标签缓存

```php
// 设置带标签的缓存
$cache->setWithTags('user:123', $userData, ['users', 'user:123'])->await();

// 按标签失效
$cache->invalidateByTags(['users'])->await();
```

## 多级缓存

```php
$cache = $cacheManager->store('multilevel');

// 第一次调用：从数据库获取，存入 L1 和 L2
$data = $cache->remember('product:123', function () {
    return $database->getProduct(123);
})->await();

// 第二次调用：从 L1 (内存) 返回，极快
$data = $cache->get('product:123')->await();

// L1 过期后：从 L2 (Redis) 返回，重新填充 L1
$data = $cache->get('product:123')->await();
```

## CLI 命令

```bash
# 清除缓存
php bin/hybrid cache clear
php bin/hybrid cache clear --store=redis
php bin/hybrid cache clear --tags=users

# 查看统计
php bin/hybrid cache stats

# 健康检查
php bin/hybrid cache health
```

## 性能特点

| 存储类型 | 1000次操作耗时 | 适用场景 |
|---------|---------------|---------|
| Memory | 2-5ms | 高频访问小数据 |
| Redis | 20-50ms | 分布式应用 |
| File | 50-100ms | 持久化缓存 |
| MultiLevel | 5-15ms (L1命中) | 推荐默认使用 |

## 下一步

- [数据库 ORM](./DATABASE.md) - 数据库操作
- [安全系统](./SECURITY.md) - 加密缓存
