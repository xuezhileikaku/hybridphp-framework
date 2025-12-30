<?php

declare(strict_types=1);

namespace HybridPHP\Core\Security;

use Amp\Future;
use HybridPHP\Core\Database\DatabaseInterface;
use function Amp\async;

/**
 * Key management service for encryption keys
 */
class KeyManager
{
    private DatabaseInterface $db;
    private EncryptionService $encryption;
    private string $keyTable = 'encryption_keys';

    public function __construct(DatabaseInterface $db, EncryptionService $encryption)
    {
        $this->db = $db;
        $this->encryption = $encryption;
    }

    /**
     * Store encryption key securely
     */
    public function storeKey(string $keyId, string $key, array $metadata = []): Future
    {
        return async(function () use ($keyId, $key, $metadata) {
            // Encrypt the key itself with master key
            $encryptedKey = $this->encryption->encrypt($key)->await();
            
            $data = [
                'key_id' => $keyId,
                'encrypted_key' => $encryptedKey,
                'metadata' => json_encode($metadata),
                'created_at' => date('Y-m-d H:i:s'),
                'is_active' => 1
            ];

            $this->db->execute(
                "INSERT INTO {$this->keyTable} (key_id, encrypted_key, metadata, created_at, is_active) 
                 VALUES (?, ?, ?, ?, ?)",
                array_values($data)
            )->await();

            return true;
        });
    }

    /**
     * Retrieve encryption key
     */
    public function getKey(string $keyId): Future
    {
        return async(function () use ($keyId) {
            $result = $this->db->query(
                "SELECT encrypted_key FROM {$this->keyTable} WHERE key_id = ? AND is_active = 1",
                [$keyId]
            )->await();

            if (empty($result)) {
                throw new \RuntimeException("Key not found: {$keyId}");
            }

            $encryptedKey = $result[0]['encrypted_key'];
            return $this->encryption->decrypt($encryptedKey)->await();
        });
    }

    /**
     * Rotate key
     */
    public function rotateKey(string $keyId, ?string $newKey = null): Future
    {
        return async(function () use ($keyId, $newKey) {
            // Generate new key if not provided
            $newKey = $newKey ?? $this->encryption->generateKey();
            
            // Deactivate old key
            $this->db->execute(
                "UPDATE {$this->keyTable} SET is_active = 0, rotated_at = ? WHERE key_id = ? AND is_active = 1",
                [date('Y-m-d H:i:s'), $keyId]
            )->await();

            // Store new key
            $this->storeKey($keyId, $newKey, [
                'rotation_reason' => 'scheduled_rotation',
                'previous_key_exists' => true
            ])->await();

            return $newKey;
        });
    }

    /**
     * List all keys with metadata
     */
    public function listKeys(): Future
    {
        return async(function () {
            $result = $this->db->query(
                "SELECT key_id, metadata, created_at, is_active, rotated_at 
                 FROM {$this->keyTable} 
                 ORDER BY created_at DESC"
            )->await();

            return array_map(function ($row) {
                $row['metadata'] = json_decode($row['metadata'], true);
                return $row;
            }, $result);
        });
    }

    /**
     * Delete key (mark as deleted, don't actually remove)
     */
    public function deleteKey(string $keyId): Future
    {
        return async(function () use ($keyId) {
            $this->db->execute(
                "UPDATE {$this->keyTable} SET is_active = 0, deleted_at = ? WHERE key_id = ?",
                [date('Y-m-d H:i:s'), $keyId]
            )->await();

            return true;
        });
    }

    /**
     * Create key management table
     */
    public function createKeyTable(): Future
    {
        return async(function () {
            $sql = "
                CREATE TABLE IF NOT EXISTS {$this->keyTable} (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    key_id VARCHAR(255) NOT NULL,
                    encrypted_key TEXT NOT NULL,
                    metadata JSON,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    rotated_at TIMESTAMP NULL,
                    deleted_at TIMESTAMP NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    INDEX idx_key_id (key_id),
                    INDEX idx_active (is_active),
                    INDEX idx_created (created_at)
                )
            ";

            $this->db->execute($sql)->await();
            return true;
        });
    }
}
