<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth\MFA;

use Amp\Future;
use HybridPHP\Core\Auth\UserInterface;
use HybridPHP\Core\Database\DatabaseInterface;
use function Amp\async;

/**
 * TOTP (Time-based One-Time Password) Manager
 */
class TOTPManager implements MFAInterface
{
    private DatabaseInterface $db;
    private array $config;

    public function __construct(DatabaseInterface $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function generateSecret(UserInterface $user): Future
    {
        return async(function () {
            return $this->generateRandomSecret();
        });
    }

    public function verifyCode(UserInterface $user, string $code, ?string $secret = null): Future
    {
        return async(function () use ($user, $code, $secret) {
            if (!$secret) {
                $mfaData = $this->getUserMFAData($user)->await();
                if (!$mfaData || !$mfaData['enabled']) {
                    return false;
                }
                $secret = $mfaData['secret'];
            }

            return $this->verifyTOTP($secret, $code);
        });
    }

    public function sendCode(UserInterface $user): Future
    {
        return async(function () {
            return true;
        });
    }

    public function getQRCodeUrl(UserInterface $user, string $secret): string
    {
        $issuer = urlencode($this->config['issuer'] ?? 'HybridPHP');
        $accountName = urlencode($user->getEmail());
        
        $otpauthUrl = "otpauth://totp/{$issuer}:{$accountName}?secret={$secret}&issuer={$issuer}";
        
        return "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($otpauthUrl);
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
        return async(function () use ($user, $secret) {
            $existing = $this->getUserMFAData($user)->await();
            
            if ($existing) {
                $this->db->execute(
                    "UPDATE user_mfa SET secret = ?, enabled = 1, updated_at = ? WHERE user_id = ?",
                    [$secret, date('Y-m-d H:i:s'), $user->getId()]
                )->await();
            } else {
                $this->db->execute(
                    "INSERT INTO user_mfa (user_id, type, secret, enabled, created_at) VALUES (?, ?, ?, 1, ?)",
                    [$user->getId(), 'totp', $secret, date('Y-m-d H:i:s')]
                )->await();
            }

            return true;
        });
    }

    public function disable(UserInterface $user): Future
    {
        return async(function () use ($user) {
            $this->db->execute(
                "UPDATE user_mfa SET enabled = 0, updated_at = ? WHERE user_id = ?",
                [date('Y-m-d H:i:s'), $user->getId()]
            )->await();

            return true;
        });
    }

    private function getUserMFAData(UserInterface $user): Future
    {
        return async(function () use ($user) {
            $result = $this->db->query(
                "SELECT * FROM user_mfa WHERE user_id = ? AND type = 'totp' LIMIT 1",
                [$user->getId()]
            )->await();

            return !empty($result) ? $result[0] : null;
        });
    }

    private function generateRandomSecret(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        
        for ($i = 0; $i < 32; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $secret;
    }

    private function verifyTOTP(string $secret, string $code): bool
    {
        $timeSlice = floor(time() / 30);
        
        for ($i = -1; $i <= 1; $i++) {
            $calculatedCode = $this->generateTOTP($secret, $timeSlice + $i);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }

    private function generateTOTP(string $secret, int $timeSlice): string
    {
        $secretKey = $this->base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord($hash[19]) & 0xf;
        $code = (
            ((ord($hash[$offset + 0]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % pow(10, $this->config['digits'] ?? 6);
        
        return str_pad((string) $code, $this->config['digits'] ?? 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32charsFlipped = array_flip(str_split($base32chars));
        
        $paddingCharCount = substr_count($secret, '=');
        $allowedValues = [6, 4, 3, 1, 0];
        
        if (!in_array($paddingCharCount, $allowedValues)) {
            return '';
        }
        
        for ($i = 0; $i < 4; $i++) {
            if ($paddingCharCount == $allowedValues[$i] &&
                substr($secret, -($allowedValues[$i])) != str_repeat('=', $allowedValues[$i])) {
                return '';
            }
        }
        
        $secret = str_replace('=', '', $secret);
        $secret = str_split($secret);
        $binaryString = '';
        
        for ($i = 0; $i < count($secret); $i = $i + 8) {
            $x = '';
            if (!in_array($secret[$i], $base32charsFlipped)) {
                return '';
            }
            for ($j = 0; $j < 8; $j++) {
                $x .= str_pad(base_convert(@$base32charsFlipped[@$secret[$i + $j]], 10, 2), 5, '0', STR_PAD_LEFT);
            }
            $eightBits = str_split($x, 8);
            for ($z = 0; $z < count($eightBits); $z++) {
                $binaryString .= (($y = chr(base_convert($eightBits[$z], 2, 10))) || ord($y) == 48) ? $y : '';
            }
        }
        
        return $binaryString;
    }
}
