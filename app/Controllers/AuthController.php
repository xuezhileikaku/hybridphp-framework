<?php

declare(strict_types=1);

namespace App\Controllers;

use Amp\Future;
use HybridPHP\Core\Controller;
use HybridPHP\Core\Auth\User;
use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use function Amp\async;

/**
 * Authentication controller
 * 
 * Updated for AMPHP v3 - using ->await() instead of yield
 */
class AuthController extends Controller
{
    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Login action
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function login(Request $request): Future
    {
        return async(function () use ($request) {
            if ($request->getMethod() === 'GET') {
                return $this->render('auth/login');
            }

            $data = $request->getParsedBody();
            $username = $data['username'] ?? '';
            $password = $data['password'] ?? '';
            $remember = !empty($data['remember']);

            if (empty($username) || empty($password)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Username and password are required'
                ], 400);
            }

            // Attempt login
            $success = $this->user->loginByUsernameAndPassword($username, $password, $remember ? 2592000 : 0)->await();

            if ($success) {
                $identity = $this->user->getIdentity()->await();
                
                // Check if MFA is enabled
                $mfaEnabled = $this->user->isMFAEnabled()->await();
                
                if ($mfaEnabled) {
                    return $this->jsonResponse([
                        'success' => true,
                        'mfa_required' => true,
                        'message' => 'MFA verification required'
                    ]);
                }

                return $this->jsonResponse([
                    'success' => true,
                    'user' => $identity->toArray(),
                    'message' => 'Login successful'
                ]);
            }

            return $this->jsonResponse([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        });
    }

    /**
     * Logout action
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function logout(Request $request): Future
    {
        return async(function () use ($request) {
            $this->user->logout()->await();

            if ($this->isApiRequest($request)) {
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Logged out successfully'
                ]);
            }

            return new Response(302, ['Location' => '/login']);
        });
    }

    /**
     * Get current user info
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function me(Request $request): Future
    {
        return async(function () use ($request) {
            $identity = $this->user->getIdentity()->await();
            
            if (!$identity) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Not authenticated'
                ], 401);
            }

            return $this->jsonResponse([
                'success' => true,
                'user' => $identity->toArray()
            ]);
        });
    }

    /**
     * Send MFA code
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function sendMFACode(Request $request): Future
    {
        return async(function () use ($request) {
            $data = $request->getParsedBody();
            $method = $data['method'] ?? 'email';

            $success = $this->user->sendMFACode($method)->await();

            return $this->jsonResponse([
                'success' => $success,
                'message' => $success ? 'MFA code sent' : 'Failed to send MFA code'
            ]);
        });
    }

    /**
     * Verify MFA code
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function verifyMFACode(Request $request): Future
    {
        return async(function () use ($request) {
            $data = $request->getParsedBody();
            $code = $data['code'] ?? '';
            $method = $data['method'] ?? 'email';

            if (empty($code)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Verification code is required'
                ], 400);
            }

            $success = $this->user->verifyMFACode($code, $method)->await();

            if ($success) {
                // Set MFA verified in session
                $request->setSession('mfa_verified', true);
                
                $identity = $this->user->getIdentity()->await();
                
                return $this->jsonResponse([
                    'success' => true,
                    'user' => $identity->toArray(),
                    'message' => 'MFA verification successful'
                ]);
            }

            return $this->jsonResponse([
                'success' => false,
                'message' => 'Invalid verification code'
            ], 400);
        });
    }

    /**
     * Enable MFA
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function enableMFA(Request $request): Future
    {
        return async(function () use ($request) {
            $data = $request->getParsedBody();
            $method = $data['method'] ?? 'totp';
            $secret = $data['secret'] ?? '';

            if ($method === 'totp' && empty($secret)) {
                // Generate TOTP secret
                $identity = $this->user->getIdentity()->await();
                $secret = $this->user->getMFAManager()->generateSecret(
                    $identity,
                    'totp'
                )->await();
                
                $qrCodeUrl = $this->user->getMFAManager()->getQRCodeUrl(
                    $identity,
                    $secret
                );

                return $this->jsonResponse([
                    'success' => true,
                    'secret' => $secret,
                    'qr_code_url' => $qrCodeUrl,
                    'message' => 'Scan QR code with your authenticator app'
                ]);
            }

            $success = $this->user->enableMFA($method, $secret)->await();

            return $this->jsonResponse([
                'success' => $success,
                'message' => $success ? 'MFA enabled successfully' : 'Failed to enable MFA'
            ]);
        });
    }

    /**
     * Disable MFA
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function disableMFA(Request $request): Future
    {
        return async(function () use ($request) {
            $data = $request->getParsedBody();
            $method = $data['method'] ?? 'totp';

            $success = $this->user->disableMFA($method)->await();

            return $this->jsonResponse([
                'success' => $success,
                'message' => $success ? 'MFA disabled successfully' : 'Failed to disable MFA'
            ]);
        });
    }

    /**
     * Generate backup codes
     *
     * @param Request $request
     * @return Future<Response>
     */
    public function generateBackupCodes(Request $request): Future
    {
        return async(function () use ($request) {
            $codes = $this->user->generateBackupCodes()->await();

            return $this->jsonResponse([
                'success' => true,
                'backup_codes' => $codes,
                'message' => 'Backup codes generated. Store them safely!'
            ]);
        });
    }

    /**
     * Check if request is API request
     *
     * @param Request $request
     * @return bool
     */
    private function isApiRequest(Request $request): bool
    {
        $acceptHeader = $request->getHeader('Accept');
        $contentType = $request->getHeader('Content-Type');
        
        return str_contains($acceptHeader, 'application/json') ||
               str_contains($contentType, 'application/json') ||
               str_starts_with($request->getPath(), '/api/');
    }
}
