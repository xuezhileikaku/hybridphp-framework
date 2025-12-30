<?php

declare(strict_types=1);

use HybridPHP\Core\Auth\User;
use HybridPHP\Core\Auth\AuthManager;
use HybridPHP\Core\Auth\RBAC\RBACManager;
use HybridPHP\Core\Auth\MFA\MFAManager;
use HybridPHP\Core\Container;

if (!function_exists('auth')) {
    /**
     * Get the authentication manager instance
     *
     * @param string|null $guard
     * @return AuthManager
     */
    function auth(?string $guard = null): AuthManager
    {
        $container = Container::getInstance();
        return $container->get('auth');
    }
}

if (!function_exists('user')) {
    /**
     * Get the user component instance
     *
     * @return User
     */
    function user(): User
    {
        $container = Container::getInstance();
        return $container->get('user');
    }
}

if (!function_exists('rbac')) {
    /**
     * Get the RBAC manager instance
     *
     * @return RBACManager
     */
    function rbac(): RBACManager
    {
        $container = Container::getInstance();
        return $container->get('rbac');
    }
}

if (!function_exists('mfa')) {
    /**
     * Get the MFA manager instance
     *
     * @return MFAManager
     */
    function mfa(): MFAManager
    {
        $container = Container::getInstance();
        return $container->get('mfa');
    }
}

if (!function_exists('can')) {
    /**
     * Check if current user has permission
     *
     * @param string $permission
     * @param array $params
     * @return \Amp\Future<bool>
     */
    function can(string $permission, array $params = []): \Amp\Future
    {
        return user()->can($permission, $params);
    }
}

if (!function_exists('hasRole')) {
    /**
     * Check if current user has role
     *
     * @param string $role
     * @return \Amp\Future<bool>
     */
    function hasRole(string $role): \Amp\Future
    {
        return user()->hasRole($role);
    }
}

if (!function_exists('isGuest')) {
    /**
     * Check if current user is guest (not authenticated)
     *
     * @return \Amp\Future<bool>
     */
    function isGuest(): \Amp\Future
    {
        return user()->getIsGuest();
    }
}

if (!function_exists('currentUser')) {
    /**
     * Get current authenticated user
     *
     * @return \Amp\Future<\HybridPHP\Core\Auth\UserInterface|null>
     */
    function currentUser(): \Amp\Future
    {
        return user()->getIdentity();
    }
}

if (!function_exists('generateJWT')) {
    /**
     * Generate JWT token for user
     *
     * @param \HybridPHP\Core\Auth\UserInterface $user
     * @param int|null $ttl
     * @return string
     */
    function generateJWT(\HybridPHP\Core\Auth\UserInterface $user, ?int $ttl = null): string
    {
        $guard = auth()->guard('jwt');
        if ($guard instanceof \HybridPHP\Core\Auth\Guards\JwtGuard) {
            return $guard->generateToken($user, $ttl);
        }
        
        throw new \InvalidArgumentException('JWT guard not available');
    }
}

if (!function_exists('parseJWT')) {
    /**
     * Parse JWT token payload
     *
     * @param string $token
     * @return array|null
     */
    function parseJWT(string $token): ?array
    {
        $guard = auth()->guard('jwt');
        if ($guard instanceof \HybridPHP\Core\Auth\Guards\JwtGuard) {
            return $guard->parseToken($token);
        }
        
        return null;
    }
}

if (!function_exists('hashPassword')) {
    /**
     * Hash password using configured algorithm
     *
     * @param string $password
     * @return string
     */
    function hashPassword(string $password): string
    {
        $container = Container::getInstance();
        $config = $container->get('config')['auth']['passwords'] ?? [];
        $algorithm = $config['hash_algorithm'] ?? PASSWORD_DEFAULT;
        
        return password_hash($password, $algorithm);
    }
}

if (!function_exists('verifyPassword')) {
    /**
     * Verify password against hash
     *
     * @param string $password
     * @param string $hash
     * @return bool
     */
    function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}

if (!function_exists('validatePassword')) {
    /**
     * Validate password against configured rules
     *
     * @param string $password
     * @return array Array of validation errors (empty if valid)
     */
    function validatePassword(string $password): array
    {
        $container = Container::getInstance();
        $config = $container->get('config')['auth']['passwords'] ?? [];
        $errors = [];
        
        $minLength = $config['min_length'] ?? 8;
        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters long";
        }
        
        if ($config['require_uppercase'] ?? true) {
            if (!preg_match('/[A-Z]/', $password)) {
                $errors[] = 'Password must contain at least one uppercase letter';
            }
        }
        
        if ($config['require_lowercase'] ?? true) {
            if (!preg_match('/[a-z]/', $password)) {
                $errors[] = 'Password must contain at least one lowercase letter';
            }
        }
        
        if ($config['require_numbers'] ?? true) {
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = 'Password must contain at least one number';
            }
        }
        
        if ($config['require_symbols'] ?? false) {
            if (!preg_match('/[^A-Za-z0-9]/', $password)) {
                $errors[] = 'Password must contain at least one special character';
            }
        }
        
        return $errors;
    }
}