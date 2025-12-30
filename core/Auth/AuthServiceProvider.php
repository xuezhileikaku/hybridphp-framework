<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth;

use HybridPHP\Core\Container;
use HybridPHP\Core\Auth\AuthManager;
use HybridPHP\Core\Auth\User;
use HybridPHP\Core\Auth\RBAC\RBACManager;
use HybridPHP\Core\Auth\MFA\MFAManager;
use HybridPHP\Core\Database\DatabaseInterface;
use HybridPHP\Core\Cache\CacheInterface;

/**
 * Authentication service provider
 */
class AuthServiceProvider
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Register authentication services
     */
    public function register(): void
    {
        // Register AuthManager
        $this->container->set('auth', function (Container $container) {
            $config = $container->get('config')['auth'] ?? [];
            return new AuthManager($container, $config);
        });

        // Register User component
        $this->container->set('user', function (Container $container) {
            $authManager = $container->get('auth');
            $config = $container->get('config')['auth'] ?? [];
            
            $user = new User($authManager, $config);
            
            // Set RBAC manager if available
            if ($container->has('rbac')) {
                $user->setRBACManager($container->get('rbac'));
            }
            
            // Set MFA manager if available
            if ($container->has('mfa')) {
                $user->setMFAManager($container->get('mfa'));
            }
            
            return $user;
        });

        // Register RBAC Manager
        $this->container->set('rbac', function (Container $container) {
            $db = $container->get('db');
            $cache = $container->has('cache') ? $container->get('cache') : null;
            $config = $container->get('config')['auth']['rbac'] ?? [];
            
            return new RBACManager($db, $config, $cache);
        });

        // Register MFA Manager
        $this->container->set('mfa', function (Container $container) {
            $db = $container->get('db');
            $cache = $container->has('cache') ? $container->get('cache') : null;
            $config = $container->get('config')['auth']['mfa'] ?? [];
            
            return new MFAManager($db, $config, $cache);
        });
    }

    /**
     * Boot authentication services
     */
    public function boot(): void
    {
        // Initialize authentication system
        $this->container->get('auth');
        $this->container->get('user');
        
        if ($this->container->get('config')['auth']['rbac']['enabled'] ?? false) {
            $this->container->get('rbac');
        }
        
        if ($this->container->get('config')['auth']['mfa']['enabled'] ?? false) {
            $this->container->get('mfa');
        }
    }
}