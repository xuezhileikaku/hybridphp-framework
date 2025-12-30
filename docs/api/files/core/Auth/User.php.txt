<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth;

use Amp\Future;
use HybridPHP\Core\Component;
use HybridPHP\Core\Auth\RBAC\RBACManager;
use HybridPHP\Core\Auth\MFA\MFAManager;
use function Amp\async;

/**
 * Yii2-style User component for authentication
 */
class User extends Component
{
    private AuthManager $authManager;
    private ?RBACManager $rbacManager = null;
    private ?MFAManager $mfaManager = null;
    private ?UserInterface $identity = null;
    private array $config;

    public function __construct(AuthManager $authManager, array $config = [])
    {
        $this->authManager = $authManager;
        $this->config = array_merge([
            'enableAutoLogin' => true,
            'loginUrl' => '/login',
            'identityCookie' => [
                'name' => '_identity',
                'httpOnly' => true,
                'secure' => false,
                'sameSite' => 'Lax',
            ],
        ], $config);
    }

    /**
     * Set RBAC manager
     *
     * @param RBACManager $rbacManager
     */
    public function setRBACManager(RBACManager $rbacManager): void
    {
        $this->rbacManager = $rbacManager;
    }

    /**
     * Set MFA manager
     *
     * @param MFAManager $mfaManager
     */
    public function setMFAManager(MFAManager $mfaManager): void
    {
        $this->mfaManager = $mfaManager;
    }

    /**
     * Login a user
     *
     * @param UserInterface $identity
     * @param int $duration
     * @return Future<bool>
     */
    public function login(UserInterface $identity, int $duration = 0): Future
    {
        return async(function () use ($identity, $duration) {
            $result = $this->authManager->login($identity, $duration > 0)->await();
            
            if ($result) {
                $this->identity = $identity;
                return true;
            }

            return false;
        });
    }

    /**
     * Login by username and password
     *
     * @param string $username
     * @param string $password
     * @param int $duration
     * @return Future<bool>
     */
    public function loginByUsernameAndPassword(string $username, string $password, int $duration = 0): Future
    {
        return async(function () use ($username, $password, $duration) {
            $identity = $this->authManager->attempt([
                'username' => $username,
                'password' => $password,
            ])->await();

            if ($identity) {
                return $this->login($identity, $duration)->await();
            }

            return false;
        });
    }

    /**
     * Login by email and password
     *
     * @param string $email
     * @param string $password
     * @param int $duration
     * @return Future<bool>
     */
    public function loginByEmailAndPassword(string $email, string $password, int $duration = 0): Future
    {
        return async(function () use ($email, $password, $duration) {
            $identity = $this->authManager->attempt([
                'email' => $email,
                'password' => $password,
            ])->await();

            if ($identity) {
                return $this->login($identity, $duration)->await();
            }

            return false;
        });
    }

    /**
     * Logout the current user
     *
     * @return Future<bool>
     */
    public function logout(): Future
    {
        return async(function () {
            $result = $this->authManager->logout()->await();
            
            if ($result) {
                $this->identity = null;
            }

            return $result;
        });
    }

    /**
     * Get the current user identity
     *
     * @return Future<UserInterface|null>
     */
    public function getIdentity(): Future
    {
        return async(function () {
            if ($this->identity === null) {
                $this->identity = $this->authManager->user()->await();
            }

            return $this->identity;
        });
    }

    /**
     * Check if user is logged in
     *
     * @return Future<bool>
     */
    public function getIsGuest(): Future
    {
        return async(function () {
            $identity = $this->getIdentity()->await();
            return $identity === null;
        });
    }

    /**
     * Get user ID
     *
     * @return Future<int|string|null>
     */
    public function getId(): Future
    {
        return async(function () {
            $identity = $this->getIdentity()->await();
            return $identity?->getId();
        });
    }

    /**
     * Check if user can perform an action
     *
     * @param string $permission
     * @param array $params
     * @return Future<bool>
     */
    public function can(string $permission, array $params = []): Future
    {
        return async(function () use ($permission, $params) {
            $identity = $this->getIdentity()->await();
            
            if (!$identity) {
                return false;
            }

            // Check direct permission
            if ($identity->hasPermission($permission)) {
                return true;
            }

            // Check through RBAC if available
            if ($this->rbacManager) {
                $resource = $params['resource'] ?? null;
                return $this->rbacManager->hasPermission($identity, $permission, $resource)->await();
            }

            return false;
        });
    }

    /**
     * Check if user has role
     *
     * @param string $role
     * @return Future<bool>
     */
    public function hasRole(string $role): Future
    {
        return async(function () use ($role) {
            $identity = $this->getIdentity()->await();
            
            if (!$identity) {
                return false;
            }

            // Check direct role
            if ($identity->hasRole($role)) {
                return true;
            }

            // Check through RBAC if available
            if ($this->rbacManager) {
                return $this->rbacManager->hasRole($identity, $role)->await();
            }

            return false;
        });
    }

    /**
     * Assign role to current user
     *
     * @param string $role
     * @return Future<bool>
     */
    public function assignRole(string $role): Future
    {
        return async(function () use ($role) {
            $identity = $this->getIdentity()->await();
            
            if (!$identity || !$this->rbacManager) {
                return false;
            }

            return $this->rbacManager->assignRole($identity, $role)->await();
        });
    }

    /**
     * Remove role from current user
     *
     * @param string $role
     * @return Future<bool>
     */
    public function removeRole(string $role): Future
    {
        return async(function () use ($role) {
            $identity = $this->getIdentity()->await();
            
            if (!$identity || !$this->rbacManager) {
                return false;
            }

            return $this->rbacManager->removeRole($identity, $role)->await();
        });
    }

    /**
     * Check if MFA is enabled for current user
     *
     * @return Future<bool>
     */
    public function isMFAEnabled(): Future
    {
        return async(function () {
            $identity = $this->getIdentity()->await();
            
            if (!$identity || !$this->mfaManager) {
                return false;
            }

            return $this->mfaManager->isEnabledForUser($identity)->await();
        });
    }

    /**
     * Send MFA code to current user
     *
     * @param string $method
     * @return Future<bool>
     */
    public function sendMFACode(string $method): Future
    {
        return async(function () use ($method) {
            $identity = $this->getIdentity()->await();
            
            if (!$identity || !$this->mfaManager) {
                return false;
            }

            return $this->mfaManager->sendCode($identity, $method)->await();
        });
    }

    /**
     * Verify MFA code for current user
     *
     * @param string $code
     * @param string $method
     * @param string|null $secret
     * @return Future<bool>
     */
    public function verifyMFACode(string $code, string $method, ?string $secret = null): Future
    {
        return async(function () use ($code, $method, $secret) {
            $identity = $this->getIdentity()->await();
            
            if (!$identity || !$this->mfaManager) {
                return false;
            }

            return $this->mfaManager->verifyCode($identity, $code, $method, $secret)->await();
        });
    }

    /**
     * Enable MFA method for current user
     *
     * @param string $method
     * @param string $secret
     * @return Future<bool>
     */
    public function enableMFA(string $method, string $secret): Future
    {
        return async(function () use ($method, $secret) {
            $identity = $this->getIdentity()->await();
            
            if (!$identity || !$this->mfaManager) {
                return false;
            }

            return $this->mfaManager->enableMethod($identity, $method, $secret)->await();
        });
    }

    /**
     * Disable MFA method for current user
     *
     * @param string $method
     * @return Future<bool>
     */
    public function disableMFA(string $method): Future
    {
        return async(function () use ($method) {
            $identity = $this->getIdentity()->await();
            
            if (!$identity || !$this->mfaManager) {
                return false;
            }

            return $this->mfaManager->disableMethod($identity, $method)->await();
        });
    }

    /**
     * Generate MFA backup codes for current user
     *
     * @return Future<array>
     */
    public function generateBackupCodes(): Future
    {
        return async(function () {
            $identity = $this->getIdentity()->await();
            
            if (!$identity || !$this->mfaManager) {
                return [];
            }

            return $this->mfaManager->generateBackupCodes($identity)->await();
        });
    }

    /**
     * Validate token and set identity
     *
     * @param string $token
     * @param string|null $guard
     * @return Future<bool>
     */
    public function loginByToken(string $token, ?string $guard = null): Future
    {
        return async(function () use ($token, $guard) {
            $identity = $this->authManager->validateToken($token, $guard)->await();
            
            if ($identity) {
                $this->identity = $identity;
                return true;
            }

            return false;
        });
    }

    /**
     * Get authentication manager
     *
     * @return AuthManager
     */
    public function getAuthManager(): AuthManager
    {
        return $this->authManager;
    }

    /**
     * Get RBAC manager
     *
     * @return RBACManager|null
     */
    public function getRBACManager(): ?RBACManager
    {
        return $this->rbacManager;
    }

    /**
     * Get MFA manager
     *
     * @return MFAManager|null
     */
    public function getMFAManager(): ?MFAManager
    {
        return $this->mfaManager;
    }
}