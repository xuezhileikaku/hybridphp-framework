<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth\MFA;

use Amp\Future;
use HybridPHP\Core\Auth\UserInterface;
use HybridPHP\Core\Database\DatabaseInterface;
use HybridPHP\Core\Cache\CacheInterface;
use function Amp\async;

/**
 * Multi-Factor Authentication Manager
 */
class MFAManager
{
    private DatabaseInterface $db;
    private ?CacheInterface $cache;
    private array $config;
    private array $providers = [];

    public function __construct(DatabaseInterface $db, array $config, ?CacheInterface $cache = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->cache = $cache;
        
        $this->initializeProviders();
    }

    private function initializeProviders(): void
    {
        if ($this->config['methods']['totp']['enabled'] ?? false) {
            $this->providers['totp'] = new TOTPManager($this->db, $this->config['methods']['totp']);
        }

        if ($this->config['methods']['email']['enabled'] ?? false) {
            $this->providers['email'] = new EmailMFAManager($this->db, $this->config['methods']['email'], $this->cache);
        }
    }

    public function isEnabled(): bool
    {
        return $this->config['enabled'] ?? false;
    }

    public function isEnabledForUser(UserInterface $user): Future
    {
        return async(function () use ($user) {
            if (!$this->isEnabled()) {
                return false;
            }

            foreach ($this->providers as $provider) {
                if ($provider->isEnabled($user)->await()) {
                    return true;
                }
            }

            return false;
        });
    }

    public function getAvailableMethods(UserInterface $user): Future
    {
        return async(function () use ($user) {
            $methods = [];

            foreach ($this->providers as $type => $provider) {
                if ($provider->isEnabled($user)->await()) {
                    $methods[] = $type;
                }
            }

            return $methods;
        });
    }

    public function generateSecret(UserInterface $user, string $method): Future
    {
        return async(function () use ($user, $method) {
            if (!isset($this->providers[$method])) {
                return null;
            }

            return $this->providers[$method]->generateSecret($user)->await();
        });
    }

    public function sendCode(UserInterface $user, string $method): Future
    {
        return async(function () use ($user, $method) {
            if (!isset($this->providers[$method])) {
                return false;
            }

            return $this->providers[$method]->sendCode($user)->await();
        });
    }

    public function verifyCode(UserInterface $user, string $code, string $method, ?string $secret = null): Future
    {
        return async(function () use ($user, $code, $method, $secret) {
            if (!isset($this->providers[$method])) {
                return false;
            }

            return $this->providers[$method]->verifyCode($user, $code, $secret)->await();
        });
    }

    public function enableMethod(UserInterface $user, string $method, string $secret): Future
    {
        return async(function () use ($user, $method, $secret) {
            if (!isset($this->providers[$method])) {
                return false;
            }

            return $this->providers[$method]->enable($user, $secret)->await();
        });
    }

    public function disableMethod(UserInterface $user, string $method): Future
    {
        return async(function () use ($user, $method) {
            if (!isset($this->providers[$method])) {
                return false;
            }

            return $this->providers[$method]->disable($user)->await();
        });
    }

    public function getQRCodeUrl(UserInterface $user, string $secret): ?string
    {
        if (isset($this->providers['totp'])) {
            return $this->providers['totp']->getQRCodeUrl($user, $secret);
        }

        return null;
    }

    public function generateBackupCodes(UserInterface $user): Future
    {
        return async(function () use ($user) {
            $codes = [];
            
            for ($i = 0; $i < 10; $i++) {
                $codes[] = $this->generateBackupCode();
            }

            $this->storeBackupCodes($user, $codes)->await();

            return $codes;
        });
    }

    public function verifyBackupCode(UserInterface $user, string $code): Future
    {
        return async(function () use ($user, $code) {
            $result = $this->db->query(
                "SELECT id FROM user_backup_codes WHERE user_id = ? AND code = ? AND used = 0 LIMIT 1",
                [$user->getId(), hash('sha256', $code)]
            )->await();

            if (empty($result)) {
                return false;
            }

            $this->db->execute(
                "UPDATE user_backup_codes SET used = 1, used_at = ? WHERE id = ?",
                [date('Y-m-d H:i:s'), $result[0]['id']]
            )->await();

            return true;
        });
    }

    public function getRemainingBackupCodesCount(UserInterface $user): Future
    {
        return async(function () use ($user) {
            $result = $this->db->query(
                "SELECT COUNT(*) as count FROM user_backup_codes WHERE user_id = ? AND used = 0",
                [$user->getId()]
            )->await();

            return $result[0]['count'] ?? 0;
        });
    }

    private function generateBackupCode(): string
    {
        return strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function storeBackupCodes(UserInterface $user, array $codes): Future
    {
        return async(function () use ($user, $codes) {
            $this->db->execute(
                "DELETE FROM user_backup_codes WHERE user_id = ?",
                [$user->getId()]
            )->await();

            foreach ($codes as $code) {
                $this->db->execute(
                    "INSERT INTO user_backup_codes (user_id, code, created_at) VALUES (?, ?, ?)",
                    [$user->getId(), hash('sha256', $code), date('Y-m-d H:i:s')]
                )->await();
            }
        });
    }
}
