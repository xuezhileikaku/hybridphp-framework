# 安全系统

HybridPHP 提供企业级安全系统，包括数据加密、密钥管理、审计日志、数据脱敏等功能。

## 核心特性

- **数据加密**: AES-256-GCM 异步加密
- **密钥管理**: 安全存储、自动轮换
- **审计日志**: 完整的安全事件记录
- **数据脱敏**: 敏感数据自动脱敏
- **TLS/SSL**: 传输层加密
- **ORM 集成**: 模型字段自动加密

## 数据加密

### 基础加密

```php
use HybridPHP\Core\Security\EncryptionService;

$encryption = new EncryptionService($encryptionKey);

// 加密数据
$encrypted = $encryption->encrypt('sensitive data')->await();

// 解密数据
$decrypted = $encryption->decrypt($encrypted)->await();

// 生成安全密钥
$key = $encryption->generateKey();

// 单向哈希
$hash = $encryption->hash('password', 'salt');
```

### 数据脱敏

```php
use HybridPHP\Core\Security\DataMasking;

$masking = new DataMasking();

// 脱敏不同类型数据
$maskedEmail = $masking->maskData('john@example.com', 'email');
// 结果: "jo**@example.com"

$maskedPhone = $masking->maskData('+1-555-123-4567', 'phone');
// 结果: "****4567"

$maskedCard = $masking->maskData('4532-1234-5678-9012', 'credit_card');
// 结果: "****9012"

// 批量脱敏
$masked = $masking->maskFields($data, [
    'name' => 'name',
    'email' => 'email',
    'phone' => 'phone'
]);

// 自动检测并脱敏
$autoMasked = $masking->autoMask($text);
```

## 密钥管理

```php
use HybridPHP\Core\Security\KeyManager;

$keyManager = new KeyManager($db, $encryption);

// 存储密钥
$keyManager->storeKey('user_data_key', $key, [
    'purpose' => 'user_encryption'
])->await();

// 获取密钥
$key = $keyManager->getKey('user_data_key')->await();

// 轮换密钥
$newKey = $keyManager->rotateKey('user_data_key')->await();

// 列出所有密钥
$keys = $keyManager->listKeys()->await();
```

## 审计日志

```php
use HybridPHP\Core\Security\AuditLogger;

$auditLogger = new AuditLogger($db, $logger, $encryption);

// 记录安全事件
$auditLogger->logSecurityEvent(
    'user_login',
    'user123',
    ['ip_address' => '192.168.1.1', 'user_agent' => 'Mozilla/5.0...'],
    'info'
)->await();

// 记录数据访问
$auditLogger->logDataAccess(
    'read',
    'users',
    '123',
    'user456',
    ['email', 'phone']
)->await();

// 记录认证事件
$auditLogger->logAuthEvent(
    'login',
    'user123',
    true,
    ['auth_method' => 'password']
)->await();

// 查询审计日志
$logs = $auditLogger->queryAuditLogs([
    'user_id' => 'user123',
    'event_type' => 'data_access',
    'date_from' => '2024-01-01',
    'date_to' => '2024-01-31'
])->await();
```

## 模型加密集成

```php
use HybridPHP\Core\Database\ORM\ActiveRecord;
use HybridPHP\Core\Security\EncryptedModelTrait;

class SecureUser extends ActiveRecord
{
    use EncryptedModelTrait;

    // 需要加密的字段
    protected function encryptedFields(): array
    {
        return ['email', 'phone', 'ssn', 'personal_notes'];
    }

    // 需要脱敏的字段
    protected function maskedFields(): array
    {
        return [
            'email' => 'email',
            'phone' => 'phone',
            'ssn' => 'ssn',
        ];
    }
}

// 使用
$user = new SecureUser([
    'name' => 'John Doe',
    'email' => 'john@example.com', // 自动加密
    'phone' => '+1-555-123-4567'   // 自动加密
]);

$user->save()->await(); // 加密后存储

$foundUser = SecureUser::findOne(['id' => 1])->await();
// 自动解密

$safeData = $foundUser->getMaskedAttributes();
// 返回脱敏版本用于日志/显示
```

## 加密缓存

```php
use HybridPHP\Core\Security\EncryptedCache;

$encryptedCache = new EncryptedCache($cache, $encryption, $auditLogger);

// 自动加密敏感缓存键
$encryptedCache->set('user_session_123', $sessionData)->await();
$encryptedCache->set('sensitive_data_456', $sensitiveData)->await();

// 自动解密
$sessionData = $encryptedCache->get('user_session_123')->await();

// 显式加密
$encryptedCache->setEncrypted('any_key', $data)->await();
$data = $encryptedCache->getDecrypted('any_key')->await();
```

## TLS 配置

```php
use HybridPHP\Core\Security\TlsConfiguration;

$tlsConfig = new TlsConfiguration();

// 获取客户端上下文选项
$clientOptions = $tlsConfig->getClientContextOptions();

// 获取服务器上下文选项
$serverOptions = $tlsConfig->getServerContextOptions(
    '/path/to/cert.pem',
    '/path/to/key.pem'
);

// 生成自签名证书（开发用）
$tlsConfig->generateSelfSignedCert(
    'storage/ssl/cert.pem',
    'storage/ssl/key.pem',
    ['commonName' => 'localhost'],
    365
);

// 验证证书
$validation = $tlsConfig->validateCertificate('storage/ssl/cert.pem');

// 检查 TLS 配置
$checks = $tlsConfig->checkTlsConfiguration();
```

## 配置

```env
# .env
APP_ENCRYPTION_KEY=your-64-character-encryption-key-here
KEY_ROTATION_INTERVAL=86400

AUDIT_LOGGING_ENABLED=true
AUDIT_LOG_RETENTION_DAYS=90

TLS_ENABLED=true
TLS_CERT_PATH=storage/ssl/server.crt
TLS_KEY_PATH=storage/ssl/server.key

CACHE_ENCRYPTION_ENABLED=true
DB_ENCRYPTION_ENABLED=true
```

## CLI 命令

```bash
# 生成加密密钥
php bin/console security key:generate --key-id=user_data_key

# 轮换密钥
php bin/console security key:rotate --key-id=user_data_key

# 列出密钥
php bin/console security key:list

# 清理旧审计日志
php bin/console security audit:clean --days=90

# 生成 TLS 证书
php bin/console security tls:generate

# 检查 TLS 配置
php bin/console security tls:check
```

## 安全最佳实践

1. **密钥管理**: 使用强随机密钥，定期轮换
2. **数据分类**: 识别敏感数据，应用适当加密
3. **审计日志**: 记录所有安全相关事件
4. **TLS/SSL**: 使用 TLS 1.2+，强密码套件
5. **访问控制**: 实施最小权限原则
6. **定期审计**: 定期审查权限和日志

## 下一步

- [认证授权](./AUTH.md) - 认证系统详解
- [日志系统](./LOGGING.md) - 日志与追踪
