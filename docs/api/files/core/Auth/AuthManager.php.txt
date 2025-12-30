<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth;

use Amp\Future;
use HybridPHP\Core\Container;
use HybridPHP\Core\Auth\Guards\JwtGuard;
use HybridPHP\Core\Auth\Guards\SessionGuard;
use HybridPHP\Core\Auth\Guards\OAuth2Guard;
use HybridPHP\Core\Auth\Providers\DatabaseUserProvider;

/**
 * Authentication manager
 */
class AuthManager
{
    private Container $container;
    private array $config;
    private array $guards = [];
    private array $providers = [];
    private ?string $defaultGuard = null;

    public function __construct(Container $container, array $config)
    {
        $this->container = $container;
        $this->config = $config;
        $this->defaultGuard = $config['default'] ?? 'jwt';
    }

    /**
     * Get authentication guard
     *
     * @param string|null $name
     * @return AuthInterface
     */
    public function guard(?string $name = null): AuthInterface
    {
        $name = $name ?: $this->defaultGuard;

        if (!isset($this->guards[$name])) {
            $this->guards[$name] = $this->createGuard($name);
        }

        return $this->guards[$name];
    }

    /**
     * Create authentication guard
     *
     * @param string $name
     * @return AuthInterface
     */
    private function createGuard(string $name): AuthInterface
    {
        $config = $this->config['guards'][$name] ?? [];
        
        if (empty($config)) {
            throw new \InvalidArgumentException("Auth guard [{$name}] is not defined.");
        }

        $provider = $this->createUserProvider($config['provider'] ?? 'users');

        return match ($config['driver']) {
            'jwt' => new JwtGuard($provider, $config),
            'session' => new SessionGuard($provider, $config),
            'oauth2' => new OAuth2Guard($provider, $config),
            default => throw new \InvalidArgumentException("Auth driver [{$config['driver']}] is not supported.")
        };
    }

    /**
     * Create user provider
     *
     * @param string $name
     * @return UserProviderInterface
     */
    private function createUserProvider(string $name): UserProviderInterface
    {
        if (!isset($this->providers[$name])) {
            $config = $this->config['providers'][$name] ?? [];
            
            if (empty($config)) {
                throw new \InvalidArgumentException("User provider [{$name}] is not defined.");
            }

            $this->providers[$name] = match ($config['driver']) {
                'database' => new DatabaseUserProvider($this->container, $config),
                default => throw new \InvalidArgumentException("User provider driver [{$config['driver']}] is not supported.")
            };
        }

        return $this->providers[$name];
    }

    /**
     * Attempt to authenticate a user
     *
     * @param array $credentials
     * @param string|null $guard
     * @return Future<UserInterface|null>
     */
    public function attempt(array $credentials, ?string $guard = null): Future
    {
        return $this->guard($guard)->attempt($credentials);
    }

    /**
     * Login a user
     *
     * @param UserInterface $user
     * @param bool $remember
     * @param string|null $guard
     * @return Future<string|bool>
     */
    public function login(UserInterface $user, bool $remember = false, ?string $guard = null): Future
    {
        return $this->guard($guard)->login($user, $remember);
    }

    /**
     * Logout the current user
     *
     * @param string|null $guard
     * @return Future<bool>
     */
    public function logout(?string $guard = null): Future
    {
        return $this->guard($guard)->logout();
    }

    /**
     * Get the currently authenticated user
     *
     * @param string|null $guard
     * @return Future<UserInterface|null>
     */
    public function user(?string $guard = null): Future
    {
        return $this->guard($guard)->user();
    }

    /**
     * Check if a user is authenticated
     *
     * @param string|null $guard
     * @return Future<bool>
     */
    public function check(?string $guard = null): Future
    {
        return $this->guard($guard)->check();
    }

    /**
     * Validate a token
     *
     * @param string $token
     * @param string|null $guard
     * @return Future<UserInterface|null>
     */
    public function validateToken(string $token, ?string $guard = null): Future
    {
        return $this->guard($guard)->validateToken($token);
    }

    /**
     * Refresh a token
     *
     * @param string $token
     * @param string|null $guard
     * @return Future<string|null>
     */
    public function refreshToken(string $token, ?string $guard = null): Future
    {
        return $this->guard($guard)->refreshToken($token);
    }
}