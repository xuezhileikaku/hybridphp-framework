<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth\MFA;

use Amp\Future;
use HybridPHP\Core\Auth\UserInterface;
use HybridPHP\Core\Database\DatabaseInterface;
use HybridPHP\Core\Cache\CacheInterface;
use function Amp\async;

/**
 * Email-based MFA Manager
 */
class EmailMFAManager implements MFAInterface
{
    private DatabaseInterface $db;
    private ?CacheInterface $cache;
    private array $config;

    public function __construct(DatabaseInterface $db, array $config, ?CacheInterface $cache = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->cache = $cache;
    }

    public function generateSecret(UserInterface $user): Future
    {
        return async(function () {
            return $this->generateCode();
        });
    }

    public function verifyCode(UserInterface $user, string $code, ?string $secret = null): Future
    {
        return async(function () use ($user, $code) {
            if (!$this->cache) {
                return false;
            }

            $cacheKey = "email_mfa_code:{$user->getId()}";
            $storedData = $this->cache->get($cacheKey)->await();

            if (!$storedData || !isset($storedData['code'], $storedData['expires_at'])) {
                return false;
            }

            if (time() > $storedData['expires_at']) {
                $this->cache->delete($cacheKey)->await();
                return false;
            }

            if (hash_equals($storedData['code'], $code)) {
                $this->cache->delete($cacheKey)->await();
                return true;
            }

            return false;
        });
    }

    public function sendCode(UserInterface $user): Future
    {
        return async(function () use ($user) {
            $code = $this->generateCode();
            $expiresAt = time() + ($this->config['ttl'] ?? 300);

            if ($this->cache) {
                $cacheKey = "email_mfa_code:{$user->getId()}";
                $this->cache->set($cacheKey, [
                    'code' => $code,
                    'expires_at' => $expiresAt,
                    'attempts' => 0,
                ], $this->config['ttl'] ?? 300)->await();
            }

            return $this->sendEmail($user, $code)->await();
        });
    }

    public function getQRCodeUrl(UserInterface $user, string $secret): string
    {
        return '';
    }

    public function isEnabled(UserInterface $user): Future
    {
        return async(function () use ($user) {
            $mfaData = $this->getUserMFAData($user)->await();
            return $mfaData && $mfaData['enabled'];
        });
    }

    public function enable(UserInterface $user, string $secret): Future
    {
        return async(function () use ($user) {
            $existing = $this->getUserMFAData($user)->await();
            
            if ($existing) {
                $this->db->execute(
                    "UPDATE user_mfa SET enabled = 1, updated_at = ? WHERE user_id = ? AND type = 'email'",
                    [date('Y-m-d H:i:s'), $user->getId()]
                )->await();
            } else {
                $this->db->execute(
                    "INSERT INTO user_mfa (user_id, type, enabled, created_at) VALUES (?, 'email', 1, ?)",
                    [$user->getId(), date('Y-m-d H:i:s')]
                )->await();
            }

            return true;
        });
    }

    public function disable(UserInterface $user): Future
    {
        return async(function () use ($user) {
            $this->db->execute(
                "UPDATE user_mfa SET enabled = 0, updated_at = ? WHERE user_id = ? AND type = 'email'",
                [date('Y-m-d H:i:s'), $user->getId()]
            )->await();

            return true;
        });
    }

    private function getUserMFAData(UserInterface $user): Future
    {
        return async(function () use ($user) {
            $result = $this->db->query(
                "SELECT * FROM user_mfa WHERE user_id = ? AND type = 'email' LIMIT 1",
                [$user->getId()]
            )->await();

            return !empty($result) ? $result[0] : null;
        });
    }

    private function generateCode(): string
    {
        $length = $this->config['code_length'] ?? 6;
        $code = '';
        
        for ($i = 0; $i < $length; $i++) {
            $code .= random_int(0, 9);
        }
        
        return $code;
    }

    private function sendEmail(UserInterface $user, string $code): Future
    {
        return async(function () use ($user, $code) {
            return true;
        });
    }
}
