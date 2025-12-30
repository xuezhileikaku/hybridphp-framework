<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth\MFA;

use Amp\Future;
use HybridPHP\Core\Auth\UserInterface;

/**
 * Multi-Factor Authentication interface
 */
interface MFAInterface
{
    /**
     * Generate MFA secret for user
     *
     * @param UserInterface $user
     * @return Future<string>
     */
    public function generateSecret(UserInterface $user): Future;

    /**
     * Verify MFA code
     *
     * @param UserInterface $user
     * @param string $code
     * @param string|null $secret
     * @return Future<bool>
     */
    public function verifyCode(UserInterface $user, string $code, ?string $secret = null): Future;

    /**
     * Send MFA code to user
     *
     * @param UserInterface $user
     * @return Future<bool>
     */
    public function sendCode(UserInterface $user): Future;

    /**
     * Get QR code URL for TOTP setup
     *
     * @param UserInterface $user
     * @param string $secret
     * @return string
     */
    public function getQRCodeUrl(UserInterface $user, string $secret): string;

    /**
     * Check if MFA is enabled for user
     *
     * @param UserInterface $user
     * @return Future<bool>
     */
    public function isEnabled(UserInterface $user): Future;

    /**
     * Enable MFA for user
     *
     * @param UserInterface $user
     * @param string $secret
     * @return Future<bool>
     */
    public function enable(UserInterface $user, string $secret): Future;

    /**
     * Disable MFA for user
     *
     * @param UserInterface $user
     * @return Future<bool>
     */
    public function disable(UserInterface $user): Future;
}