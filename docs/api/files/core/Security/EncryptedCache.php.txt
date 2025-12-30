<?php

declare(strict_types=1);

namespace HybridPHP\Core\Security;

use Amp\Future;
use HybridPHP\Core\Cache\CacheInterface;
use function Amp\async;

/**
 * Encrypted cache wrapper that encrypts data before storing
 */
class EncryptedCache implements CacheInterface
{
    private CacheInterface $cache;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private array $sensitiveKeyPatterns = [];

    public function __construct(
        CacheInterface $cache,
        EncryptionService $encryption,
        AuditLogger $auditLogger
    ) {
        $this->cache = $cache;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;

        // Default patterns for sensitive cache keys
        $this->sensitiveKeyPatterns = [
            '/user_session_/',
            '/auth_token_/',
            '/password_reset_/',
            '/sensitive_data_/',
            '/personal_info_/',
            '/payment_/',
            '/credit_card_/'
        ];
    }

    /**
     * Get cache value with automatic decryption
     */
    public function get(string $key, mixed $default = null): Future
    {
        return async(function () use ($key, $default) {
            $encryptedValue = $this->cache->get($key, null)->await();

            if ($encryptedValue === null) {
                return $default;
            }

            // Log cache access for sensitive keys
            if ($this->isSensitiveKey($key)) {
                $this->auditLogger->logSecurityEvent(
                    'cache_access',
                    $this->getCurrentUserId(),
                    ['key' => $key, 'action' => 'get'],
                    'info'
                )->await();
            }

            // Try to decrypt if it looks encrypted
            if ($this->shouldEncrypt($key) && $this->isEncrypted($encryptedValue)) {
                try {
                    $decrypted = $this->encryption->decrypt($encryptedValue)->await();
                    return unserialize($decrypted);
                } catch (\Exception $e) {
                    // Try with historical keys
                    try {
                        $decrypted = $this->encryption->decryptWithHistoricalKeys($encryptedValue)->await();
                        return unserialize($decrypted);
                    } catch (\Exception $e) {
                        // Log decryption failure
                        $this->auditLogger->logSecurityEvent(
                            'cache_decrypt_failed',
                            $this->getCurrentUserId(),
                            ['key' => $key, 'error' => $e->getMessage()],
                            'error'
                        )->await();
                        return $default;
                    }
                }
            }

            return unserialize($encryptedValue);
        });
    }

    /**
     * Set cache value with automatic encryption
     */
    public function set(string $key, mixed $value, ?int $ttl = null): Future
    {
        return async(function () use ($key, $value, $ttl) {
            $serialized = serialize($value);

            // Encrypt sensitive data
            if ($this->shouldEncrypt($key)) {
                $serialized = $this->encryption->encrypt($serialized)->await();

                // Log encryption event
                $this->auditLogger->logEncryptionEvent(
                    'encrypt',
                    'cache_' . $key,
                    $this->getCurrentUserId(),
                    true
                )->await();
            }

            // Log cache write for sensitive keys
            if ($this->isSensitiveKey($key)) {
                $this->auditLogger->logSecurityEvent(
                    'cache_write',
                    $this->getCurrentUserId(),
                    ['key' => $key, 'ttl' => $ttl],
                    'info'
                )->await();
            }

            return $this->cache->set($key, $serialized, $ttl)->await();
        });
    }

    /**
     * Delete cache key
     */
    public function delete(string $key): Future
    {
        return async(function () use ($key) {
            // Log cache deletion for sensitive keys
            if ($this->isSensitiveKey($key)) {
                $this->auditLogger->logSecurityEvent(
                    'cache_delete',
                    $this->getCurrentUserId(),
                    ['key' => $key],
                    'info'
                )->await();
            }

            return $this->cache->delete($key)->await();
        });
    }

    /**
     * Check if cache key exists
     */
    public function has(string $key): Future
    {
        return $this->cache->has($key);
    }

    /**
     * Get multiple cache values
     */
    public function getMultiple(array $keys, mixed $default = null): Future
    {
        return async(function () use ($keys, $default) {
            $results = [];

            foreach ($keys as $key) {
                $results[$key] = $this->get($key, $default)->await();
            }

            return $results;
        });
    }

    /**
     * Set multiple cache values
     */
    public function setMultiple(array $values, ?int $ttl = null): Future
    {
        return async(function () use ($values, $ttl) {
            $results = [];

            foreach ($values as $key => $value) {
                $results[$key] = $this->set($key, $value, $ttl)->await();
            }

            return $results;
        });
    }

    /**
     * Delete multiple cache keys
     */
    public function deleteMultiple(array $keys): Future
    {
        return async(function () use ($keys) {
            $results = [];

            foreach ($keys as $key) {
                $results[$key] = $this->delete($key)->await();
            }

            return $results;
        });
    }

    /**
     * Clear all cache
     */
    public function clear(): Future
    {
        return async(function () {
            // Log cache clear event
            $this->auditLogger->logSecurityEvent(
                'cache_clear',
                $this->getCurrentUserId(),
                [],
                'warning'
            )->await();

            return $this->cache->clear()->await();
        });
    }

    /**
     * Check if key should be encrypted
     */
    protected function shouldEncrypt(string $key): bool
    {
        return $this->isSensitiveKey($key);
    }

    /**
     * Check if key is sensitive
     */
    protected function isSensitiveKey(string $key): bool
    {
        foreach ($this->sensitiveKeyPatterns as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if value is encrypted
     */
    protected function isEncrypted(string $value): bool
    {
        return strlen($value) > 50 && base64_encode(base64_decode($value, true)) === $value;
    }

    /**
     * Add sensitive key pattern
     */
    public function addSensitiveKeyPattern(string $pattern): void
    {
        $this->sensitiveKeyPatterns[] = $pattern;
    }

    /**
     * Get current user ID for audit logging
     */
    protected function getCurrentUserId(): string
    {
        if (isset($_SESSION['user_id'])) {
            return (string) $_SESSION['user_id'];
        }

        if (function_exists('auth') && auth()->check()) {
            return (string) auth()->id();
        }

        return 'system';
    }

    /**
     * Set cache with explicit encryption
     */
    public function setEncrypted(string $key, mixed $value, ?int $ttl = null): Future
    {
        return async(function () use ($key, $value, $ttl) {
            $serialized = serialize($value);
            $encrypted = $this->encryption->encrypt($serialized)->await();

            $this->auditLogger->logEncryptionEvent(
                'encrypt',
                'cache_' . $key,
                $this->getCurrentUserId(),
                true
            )->await();

            return $this->cache->set($key, $encrypted, $ttl)->await();
        });
    }

    /**
     * Get cache with explicit decryption
     */
    public function getDecrypted(string $key, mixed $default = null): Future
    {
        return async(function () use ($key, $default) {
            $encryptedValue = $this->cache->get($key, null)->await();

            if ($encryptedValue === null) {
                return $default;
            }

            try {
                $decrypted = $this->encryption->decrypt($encryptedValue)->await();
                return unserialize($decrypted);
            } catch (\Exception $e) {
                try {
                    $decrypted = $this->encryption->decryptWithHistoricalKeys($encryptedValue)->await();
                    return unserialize($decrypted);
                } catch (\Exception $e) {
                    $this->auditLogger->logSecurityEvent(
                        'cache_decrypt_failed',
                        $this->getCurrentUserId(),
                        ['key' => $key, 'error' => $e->getMessage()],
                        'error'
                    )->await();
                    return $default;
                }
            }
        });
    }
}
