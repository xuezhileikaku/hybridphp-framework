<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth\Guards;

use Amp\Future;
use HybridPHP\Core\Auth\AuthInterface;
use HybridPHP\Core\Auth\UserInterface;
use HybridPHP\Core\Auth\UserProviderInterface;
use HybridPHP\Core\Cache\CacheInterface;
use function Amp\async;

/**
 * Session-based authentication guard
 */
class SessionGuard implements AuthInterface
{
    private UserProviderInterface $provider;
    private array $config;
    private ?CacheInterface $cache;
    private ?UserInterface $user = null;
    private ?string $sessionId = null;

    public function __construct(UserProviderInterface $provider, array $config, ?CacheInterface $cache = null)
    {
        $this->provider = $provider;
        $this->config = $config;
        $this->cache = $cache;
    }

    /**
     * Attempt to authenticate a user
     *
     * @param array $credentials
     * @return Future<UserInterface|null>
     */
    public function attempt(array $credentials): Future
    {
        return async(function () use ($credentials) {
            $user = $this->provider->retrieveByCredentials($credentials)->await();
            
            if ($user && $this->provider->validateCredentials($user, $credentials)->await()) {
                $this->user = $user;
                return $user;
            }

            return null;
        });
    }

    /**
     * Login a user and create session
     *
     * @param UserInterface $user
     * @param bool $remember
     * @return Future<string>
     */
    public function login(UserInterface $user, bool $remember = false): Future
    {
        return async(function () use ($user, $remember) {
            $this->user = $user;
            $this->sessionId = $this->generateSessionId();
            
            $sessionData = [
                'user_id' => $user->getId(),
                'username' => $user->getUsername(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
                'created_at' => time(),
                'last_activity' => time(),
                'remember' => $remember,
            ];

            $ttl = $remember ? 2592000 : $this->config['lifetime']; // 30 days or configured lifetime
            
            if ($this->cache) {
                $this->cache->set("session:{$this->sessionId}", $sessionData, $ttl)->await();
            }

            return $this->sessionId;
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
            if ($this->sessionId && $this->cache) {
                $this->cache->delete("session:{$this->sessionId}")->await();
            }
            
            $this->user = null;
            $this->sessionId = null;
            return true;
        });
    }

    /**
     * Get the currently authenticated user
     *
     * @return Future<UserInterface|null>
     */
    public function user(): Future
    {
        return async(function () {
            return $this->user;
        });
    }

    /**
     * Check if a user is authenticated
     *
     * @return Future<bool>
     */
    public function check(): Future
    {
        return async(function () {
            return $this->user !== null;
        });
    }

    /**
     * Get the user ID
     *
     * @return Future<int|string|null>
     */
    public function id(): Future
    {
        return async(function () {
            return $this->user?->getId();
        });
    }

    /**
     * Validate a session token
     *
     * @param string $token
     * @return Future<UserInterface|null>
     */
    public function validateToken(string $token): Future
    {
        return async(function () use ($token) {
            if (!$this->cache) {
                return null;
            }

            $sessionData = $this->cache->get("session:{$token}")->await();
            
            if (!$sessionData || !isset($sessionData['user_id'])) {
                return null;
            }

            // Check session expiry
            $maxLifetime = $sessionData['remember'] ? 2592000 : $this->config['lifetime'];
            if (time() - $sessionData['last_activity'] > $maxLifetime) {
                $this->cache->delete("session:{$token}")->await();
                return null;
            }

            $user = $this->provider->retrieveById($sessionData['user_id'])->await();
            
            if ($user && $user->isActive()) {
                $this->user = $user;
                $this->sessionId = $token;
                
                // Update last activity
                $sessionData['last_activity'] = time();
                $this->cache->set("session:{$token}", $sessionData, $maxLifetime)->await();
                
                return $user;
            }

            return null;
        });
    }

    /**
     * Refresh a session token
     *
     * @param string $token
     * @return Future<string|null>
     */
    public function refreshToken(string $token): Future
    {
        return async(function () use ($token) {
            $user = $this->validateToken($token)->await();
            
            if ($user) {
                // Generate new session ID
                $newSessionId = $this->generateSessionId();
                
                if ($this->cache) {
                    $sessionData = $this->cache->get("session:{$token}")->await();
                    if ($sessionData) {
                        // Delete old session
                        $this->cache->delete("session:{$token}")->await();
                        
                        // Create new session
                        $sessionData['last_activity'] = time();
                        $ttl = $sessionData['remember'] ? 2592000 : $this->config['lifetime'];
                        $this->cache->set("session:{$newSessionId}", $sessionData, $ttl)->await();
                        
                        $this->sessionId = $newSessionId;
                        return $newSessionId;
                    }
                }
            }

            return null;
        });
    }

    /**
     * Generate a unique session ID
     *
     * @return string
     */
    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Set cache instance
     *
     * @param CacheInterface $cache
     */
    public function setCache(CacheInterface $cache): void
    {
        $this->cache = $cache;
    }

    /**
     * Get current session ID
     *
     * @return string|null
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }
}