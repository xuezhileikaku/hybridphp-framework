<?php

namespace HybridPHP\Core\Cache;

use HybridPHP\Core\Container;
use HybridPHP\Core\ConfigManager;

/**
 * Cache Service Provider
 */
class CacheServiceProvider
{
    public function register(Container $container): void
    {
        // Register cache manager
        $container->singleton(CacheManager::class, function () use ($container) {
            $configManager = $container->get(ConfigManager::class);
            return new CacheManager($configManager);
        });

        // Register cache interface binding
        $container->bind(CacheInterface::class, function () use ($container) {
            $manager = $container->get(CacheManager::class);
            return $manager->store();
        });

        // Register specific cache stores
        $container->bind('cache.redis', function () use ($container) {
            $manager = $container->get(CacheManager::class);
            return $manager->store('redis');
        });

        $container->bind('cache.memory', function () use ($container) {
            $manager = $container->get(CacheManager::class);
            return $manager->store('memory');
        });

        $container->bind('cache.multilevel', function () use ($container) {
            $manager = $container->get(CacheManager::class);
            return $manager->store('multilevel');
        });

        $container->bind('cache.file', function () use ($container) {
            $manager = $container->get(CacheManager::class);
            return $manager->store('file');
        });
    }

    public function boot(Container $container): void
    {
        // Perform any cache warming if enabled
        $configManager = $container->get(ConfigManager::class);
        $warmingConfig = $configManager->get('cache.warming', []);
        
        if ($warmingConfig['enabled'] ?? false) {
            $this->performCacheWarming($container);
        }
    }

    private function performCacheWarming(Container $container): void
    {
        // This could be extended to warm up specific cache keys
        // For now, it's a placeholder for future implementation
    }
}