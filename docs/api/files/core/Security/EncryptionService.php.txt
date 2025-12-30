<?php

declare(strict_types=1);

namespace HybridPHP\Core\Security;

use Amp\Future;
use function Amp\async;
use function Amp\delay;

/**
 * Async encryption service implementation
 */
class EncryptionService implements EncryptionInterface
{
    private string $defaultKey;
    private string $cipher = 'aes-256-gcm';
    private array $keyRotationHistory = [];

    public function __construct(string $defaultKey)
    {
        if (strlen($defaultKey) < 32) {
            throw new \InvalidArgumentException('Encryption key must be at least 32 characters long');
        }
        $this->defaultKey = $defaultKey;
    }

    /**
     * Encrypt data asynchronously
     */
    public function encrypt(string $data, ?string $key = null): Future
    {
        return async(function () use ($data, $key) {
            $encryptionKey = $key ?? $this->defaultKey;
            
            // Generate random IV
            $iv = random_bytes(16);
            
            // Generate authentication tag
            $tag = '';
            
            // Encrypt the data
            $encrypted = openssl_encrypt(
                $data,
                $this->cipher,
                $encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($encrypted === false) {
                throw new \RuntimeException('Encryption failed');
            }

            // Combine IV, tag, and encrypted data
            $result = base64_encode($iv . $tag . $encrypted);
            
            // Add small delay to prevent timing attacks
            delay(0.001);
            
            return $result;
        });
    }

    /**
     * Decrypt data asynchronously
     */
    public function decrypt(string $encryptedData, ?string $key = null): Future
    {
        return async(function () use ($encryptedData, $key) {
            $encryptionKey = $key ?? $this->defaultKey;
            
            try {
                $data = base64_decode($encryptedData);
                
                if ($data === false || strlen($data) < 32) {
                    throw new \RuntimeException('Invalid encrypted data format');
                }
                
                // Extract IV, tag, and encrypted data
                $iv = substr($data, 0, 16);
                $tag = substr($data, 16, 16);
                $encrypted = substr($data, 32);
                
                // Decrypt the data
                $decrypted = openssl_decrypt(
                    $encrypted,
                    $this->cipher,
                    $encryptionKey,
                    OPENSSL_RAW_DATA,
                    $iv,
                    $tag
                );

                if ($decrypted === false) {
                    throw new \RuntimeException('Decryption failed');
                }

                // Add small delay to prevent timing attacks
                delay(0.001);
                
                return $decrypted;
            } catch (\Exception $e) {
                // Add delay even on failure to prevent timing attacks
                delay(0.001);
                throw new \RuntimeException('Decryption failed: ' . $e->getMessage());
            }
        });
    }

    /**
     * Generate encryption key
     */
    public function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash data (one-way)
     */
    public function hash(string $data, ?string $salt = null): string
    {
        // If salt is provided, append it to data before hashing
        $dataToHash = $salt ? $data . $salt : $data;
        
        return password_hash($dataToHash, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536, // 64 MB
            'time_cost' => 4,       // 4 iterations
            'threads' => 3,         // 3 threads
        ]);
    }

    /**
     * Verify hash
     */
    public function verifyHash(string $data, string $hash, ?string $salt = null): bool
    {
        // If salt is provided, append it to data before verification
        $dataToVerify = $salt ? $data . $salt : $data;
        
        return password_verify($dataToVerify, $hash);
    }

    /**
     * Generate secure random string
     */
    public function generateSecureRandom(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Mask sensitive data for logging/display
     */
    public function maskSensitiveData(string $data, int $visibleChars = 4): string
    {
        $length = strlen($data);
        
        if ($length <= $visibleChars * 2) {
            return str_repeat('*', $length);
        }
        
        $start = substr($data, 0, $visibleChars);
        $end = substr($data, -$visibleChars);
        $masked = str_repeat('*', $length - ($visibleChars * 2));
        
        return $start . $masked . $end;
    }

    /**
     * Rotate encryption key
     */
    public function rotateKey(string $newKey): void
    {
        if (strlen($newKey) < 32) {
            throw new \InvalidArgumentException('New encryption key must be at least 32 characters long');
        }
        
        // Store old key for potential decryption of old data
        $this->keyRotationHistory[] = [
            'key' => $this->defaultKey,
            'rotated_at' => time()
        ];
        
        // Keep only last 5 keys for rotation history
        if (count($this->keyRotationHistory) > 5) {
            array_shift($this->keyRotationHistory);
        }
        
        $this->defaultKey = $newKey;
    }

    /**
     * Get key rotation history
     */
    public function getKeyRotationHistory(): array
    {
        return array_map(function ($entry) {
            return [
                'key_hash' => hash('sha256', $entry['key']),
                'rotated_at' => $entry['rotated_at']
            ];
        }, $this->keyRotationHistory);
    }

    /**
     * Try to decrypt with historical keys
     */
    public function decryptWithHistoricalKeys(string $encryptedData): Future
    {
        return async(function () use ($encryptedData) {
            // Try current key first
            try {
                $future = $this->decrypt($encryptedData);
                return $future->await();
            } catch (\Exception $e) {
                // Try historical keys
                foreach ($this->keyRotationHistory as $keyEntry) {
                    try {
                        $future = $this->decrypt($encryptedData, $keyEntry['key']);
                        return $future->await();
                    } catch (\Exception $e) {
                        continue;
                    }
                }
                
                throw new \RuntimeException('Unable to decrypt data with any available keys');
            }
        });
    }
}