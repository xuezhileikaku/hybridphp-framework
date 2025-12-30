# HybridPHP Security System Documentation

## Overview

The HybridPHP Security System provides comprehensive data security and encryption capabilities, including:

- **Data Encryption**: Async encryption/decryption for sensitive data
- **Key Management**: Secure key storage, rotation, and management
- **Audit Logging**: Comprehensive security event logging
- **Data Masking**: Sensitive data masking and anonymization
- **TLS/SSL Support**: Secure transmission encryption
- **Cache Encryption**: Encrypted cache storage for sensitive data
- **ORM Integration**: Seamless encryption integration with database models

## Components

### 1. Encryption Service (`EncryptionService`)

Provides async encryption and decryption capabilities using AES-256-GCM.

```php
use HybridPHP\Core\Security\EncryptionService;

$encryption = new EncryptionService($encryptionKey);

// Encrypt data
$encrypted = $encryption->encrypt('sensitive data')->await();

// Decrypt data
$decrypted = $encryption->decrypt($encrypted)->await();

// Generate secure key
$key = $encryption->generateKey();

// Hash data (one-way)
$hash = $encryption->hash('password', 'salt');

// Mask sensitive data
$masked = $encryption->maskSensitiveData('john@example.com', 4);
// Result: "jo**@example.com"
```

### 2. Key Management (`KeyManager`)

Manages encryption keys with rotation and secure storage.

```php
use HybridPHP\Core\Security\KeyManager;

$keyManager = new KeyManager($db, $encryption);

// Store a key
$keyManager->storeKey('user_data_key', $key, ['purpose' => 'user_encryption'])->await();

// Retrieve a key
$key = $keyManager->getKey('user_data_key')->await();

// Rotate a key
$newKey = $keyManager->rotateKey('user_data_key')->await();

// List all keys
$keys = $keyManager->listKeys()->await();
```

### 3. Audit Logging (`AuditLogger`)

Comprehensive security event logging with encrypted context data.

```php
use HybridPHP\Core\Security\AuditLogger;

$auditLogger = new AuditLogger($db, $logger, $encryption);

// Log security event
$auditLogger->logSecurityEvent(
    'user_login',
    'user123',
    ['ip_address' => '192.168.1.1', 'user_agent' => 'Mozilla/5.0...'],
    'info'
)->await();

// Log data access
$auditLogger->logDataAccess(
    'read',
    'users',
    '123',
    'user456',
    ['email', 'phone']
)->await();

// Log authentication event
$auditLogger->logAuthEvent(
    'login',
    'user123',
    true,
    ['auth_method' => 'password']
)->await();

// Query audit logs
$logs = $auditLogger->queryAuditLogs([
    'user_id' => 'user123',
    'event_type' => 'data_access',
    'date_from' => '2024-01-01',
    'date_to' => '2024-01-31'
])->await();
```

### 4. Data Masking (`DataMasking`)

Masks and anonymizes sensitive data for logging and display.

```php
use HybridPHP\Core\Security\DataMasking;

$masking = new DataMasking();

// Mask different data types
$maskedEmail = $masking->maskData('john.doe@example.com', 'email');
// Result: "jo**@example.com"

$maskedPhone = $masking->maskData('+1-555-123-4567', 'phone');
// Result: "****4567"

$maskedCard = $masking->maskData('4532-1234-5678-9012', 'credit_card');
// Result: "****9012"

// Mask multiple fields
$data = [
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'phone' => '+1-555-123-4567'
];

$masked = $masking->maskFields($data, [
    'name' => 'name',
    'email' => 'email',
    'phone' => 'phone'
]);

// Auto-detect and mask sensitive data
$text = "Contact John at john@example.com or call +1-555-123-4567";
$autoMasked = $masking->autoMask($text);
```

### 5. Encrypted Model Trait (`EncryptedModelTrait`)

Seamlessly integrates encryption with ORM models.

```php
use HybridPHP\Core\Database\ORM\ActiveRecord;
use HybridPHP\Core\Security\EncryptedModelTrait;

class SecureUser extends ActiveRecord
{
    use EncryptedModelTrait;

    protected function encryptedFields(): array
    {
        return ['email', 'phone', 'ssn', 'personal_notes'];
    }

    protected function maskedFields(): array
    {
        return [
            'email' => 'email',
            'phone' => 'phone',
            'ssn' => 'ssn',
            'name' => 'name'
        ];
    }
}

// Usage
$user = new SecureUser([
    'name' => 'John Doe',
    'email' => 'john@example.com', // Will be encrypted
    'phone' => '+1-555-123-4567'   // Will be encrypted
]);

$user->save()->await(); // Automatically encrypts sensitive fields

$foundUser = SecureUser::findOne(['id' => 1])->await();
// Automatically decrypts sensitive fields

$safeData = $foundUser->getMaskedAttributes();
// Returns masked version for logging/display
```

### 6. Encrypted Cache (`EncryptedCache`)

Transparent cache encryption for sensitive data.

```php
use HybridPHP\Core\Security\EncryptedCache;

$encryptedCache = new EncryptedCache($cache, $encryption, $auditLogger);

// Automatically encrypts sensitive cache keys
$encryptedCache->set('user_session_123', $sessionData)->await();
$encryptedCache->set('sensitive_data_456', $sensitiveData)->await();

// Automatically decrypts when retrieving
$sessionData = $encryptedCache->get('user_session_123')->await();

// Explicit encryption
$encryptedCache->setEncrypted('any_key', $data)->await();
$data = $encryptedCache->getDecrypted('any_key')->await();
```

### 7. TLS Configuration (`TlsConfiguration`)

Manages TLS/SSL configuration for secure transmission.

```php
use HybridPHP\Core\Security\TlsConfiguration;

$tlsConfig = new TlsConfiguration();

// Get client context options
$clientOptions = $tlsConfig->getClientContextOptions();

// Get server context options
$serverOptions = $tlsConfig->getServerContextOptions(
    '/path/to/cert.pem',
    '/path/to/key.pem'
);

// Generate self-signed certificate (development)
$success = $tlsConfig->generateSelfSignedCert(
    'storage/ssl/cert.pem',
    'storage/ssl/key.pem',
    ['commonName' => 'localhost'],
    365
);

// Validate certificate
$validation = $tlsConfig->validateCertificate('storage/ssl/cert.pem');

// Check TLS configuration
$checks = $tlsConfig->checkTlsConfiguration();
```

## Configuration

### Environment Variables

```bash
# Encryption
APP_ENCRYPTION_KEY=your-64-character-encryption-key-here
KEY_ROTATION_INTERVAL=86400

# Audit Logging
AUDIT_LOGGING_ENABLED=true
AUDIT_LOG_RETENTION_DAYS=90

# TLS/SSL
TLS_ENABLED=true
TLS_CERT_PATH=storage/ssl/server.crt
TLS_KEY_PATH=storage/ssl/server.key
TLS_ALLOW_SELF_SIGNED=false

# Cache Encryption
CACHE_ENCRYPTION_ENABLED=true

# Database Encryption
DB_ENCRYPTION_ENABLED=true

# Security Monitoring
SECURITY_MONITORING_ENABLED=true
SECURITY_ALERT_EMAIL=admin@example.com

# Compliance
COMPLIANCE_ENABLED=true
```

### Configuration File (`config/security.php`)

The security system is configured through `config/security.php`. Key sections include:

- **Encryption**: Key management and cipher configuration
- **Audit**: Logging configuration and retention policies
- **Masking**: Data masking rules and patterns
- **TLS**: SSL/TLS configuration and security headers
- **Cache Encryption**: Sensitive cache key patterns
- **Database Encryption**: Default encrypted fields
- **Monitoring**: Security monitoring and alerting
- **Compliance**: GDPR/HIPAA compliance features

## Console Commands

### Security Management Command

```bash
# Generate encryption key
php bin/console security key:generate --key-id=user_data_key

# Rotate encryption key
php bin/console security key:rotate --key-id=user_data_key

# List encryption keys
php bin/console security key:list

# Clean old audit logs
php bin/console security audit:clean --days=90

# Generate TLS certificate
php bin/console security tls:generate --cert-path=storage/ssl/cert.pem --key-path=storage/ssl/key.pem

# Check TLS configuration
php bin/console security tls:check
```

## Database Schema

### Encryption Keys Table

```sql
CREATE TABLE encryption_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_id VARCHAR(255) NOT NULL,
    encrypted_key TEXT NOT NULL,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    rotated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_key_id (key_id),
    INDEX idx_active (is_active)
);
```

### Audit Logs Table

```sql
CREATE TABLE audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    context TEXT, -- Encrypted
    severity ENUM('debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency') DEFAULT 'info',
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    session_id VARCHAR(255),
    request_id VARCHAR(255),
    INDEX idx_event_type (event_type),
    INDEX idx_user_id (user_id),
    INDEX idx_timestamp (timestamp)
);
```

## Security Best Practices

### 1. Key Management

- Use strong, randomly generated encryption keys (64+ characters)
- Rotate keys regularly (recommended: every 30-90 days)
- Store keys securely (environment variables, key management services)
- Never commit keys to version control
- Use different keys for different environments

### 2. Data Classification

- Identify and classify sensitive data
- Apply appropriate encryption levels
- Use data masking for non-production environments
- Implement data retention policies

### 3. Audit Logging

- Log all security-relevant events
- Encrypt sensitive context data in logs
- Implement log retention and cleanup policies
- Monitor logs for suspicious activities
- Set up alerting for critical events

### 4. TLS/SSL

- Use TLS 1.2 or higher
- Use strong cipher suites
- Implement proper certificate validation
- Set security headers
- Use HSTS for web applications

### 5. Access Control

- Implement principle of least privilege
- Use role-based access control (RBAC)
- Log all data access events
- Implement session management
- Use multi-factor authentication where appropriate

## Testing

Run the security system test:

```bash
php test_security_system.php
```

This test covers:
- Encryption/decryption functionality
- Data masking capabilities
- TLS configuration
- Encrypted cache operations
- Audit logging
- Key management
- Secure model operations

## Compliance Features

### GDPR Compliance

- **Right to be Forgotten**: Secure data deletion/anonymization
- **Data Portability**: Export user data in standard formats
- **Consent Management**: Track and manage user consent
- **Data Minimization**: Encrypt only necessary data
- **Audit Trail**: Complete audit logs for data processing

### HIPAA Compliance

- **Data Encryption**: At-rest and in-transit encryption
- **Access Logging**: Comprehensive audit trails
- **User Authentication**: Strong authentication mechanisms
- **Data Integrity**: Tamper-evident audit logs
- **Risk Assessment**: Security monitoring and alerting

## Performance Considerations

- Encryption operations are async and non-blocking
- Use connection pooling for database operations
- Implement caching for frequently accessed encrypted data
- Consider batch operations for bulk encryption/decryption
- Monitor performance impact of encryption overhead

## Troubleshooting

### Common Issues

1. **Decryption Failures**
   - Check encryption key configuration
   - Verify key rotation history
   - Check for data corruption

2. **Performance Issues**
   - Monitor encryption overhead
   - Optimize database queries
   - Use appropriate caching strategies

3. **TLS Configuration**
   - Verify certificate validity
   - Check cipher suite compatibility
   - Validate TLS version support

4. **Audit Log Growth**
   - Implement log rotation
   - Set appropriate retention policies
   - Monitor disk space usage

## Security Considerations

- Regularly update encryption algorithms and key sizes
- Monitor for security vulnerabilities in dependencies
- Implement proper error handling to prevent information leakage
- Use secure random number generation
- Implement rate limiting for sensitive operations
- Regular security audits and penetration testing

## Integration Examples

### With Authentication System

```php
// Log authentication events
$auditLogger->logAuthEvent('login', $userId, true, [
    'auth_method' => 'password',
    'ip_address' => $request->getClientIp(),
    'user_agent' => $request->getHeaderLine('User-Agent')
])->await();
```

### With API Responses

```php
// Return masked data in API responses
$user = SecureUser::findOne(['id' => $userId])->await();
$response = [
    'user' => $user->getSafeAttributes(),
    'sensitive_data_masked' => true
];
```

### With Middleware

```php
// Security middleware for audit logging
class SecurityAuditMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)->await();
        
        // Log API access
        $this->auditLogger->logSecurityEvent(
            'api_access',
            $this->getCurrentUserId($request),
            [
                'endpoint' => $request->getUri()->getPath(),
                'method' => $request->getMethod(),
                'ip_address' => $request->getClientIp()
            ]
        )->await();
        
        return $response;
    }
}
```