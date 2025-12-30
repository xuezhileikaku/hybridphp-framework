<?php

declare(strict_types=1);

namespace HybridPHP\Core\Security;

use Amp\Future;

/**
 * Encryption service interface for data security
 */
interface EncryptionInterface
{
    /**
     * Encrypt data
     */
    public function encrypt(string $data, ?string $key = null): Future;

    /**
     * Decrypt data
     */
    public function decrypt(string $encryptedData, ?string $key = null): Future;

    /**
     * Generate encryption key
     */
    public function generateKey(): string;

    /**
     * Hash data (one-way)
     */
    public function hash(string $data, ?string $salt = null): string;

    /**
     * Verify hash
     */
    public function verifyHash(string $data, string $hash, ?string $salt = null): bool;

    /**
     * Generate secure random string
     */
    public function generateSecureRandom(int $length = 32): string;

    /**
     * Mask sensitive data for logging/display
     */
    public function maskSensitiveData(string $data, int $visibleChars = 4): string;
}