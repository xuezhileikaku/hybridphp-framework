<?php

declare(strict_types=1);

/**
 * HybridPHP Framework Helper Functions
 */

if (!function_exists('env')) {
    /**
     * Get environment variable value
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        
        if ($value === false) {
            return $default;
        }
        
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
            case 'empty':
            case '(empty)':
                return '';
        }
        
        return $value;
    }
}

if (!function_exists('config')) {
    /**
     * Get configuration value
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $config = null;
        
        if ($config === null) {
            $config = \HybridPHP\Core\Container::getInstance()->get(\HybridPHP\Core\ConfigManager::class);
        }
        
        return $config->get($key, $default);
    }
}

if (!function_exists('app')) {
    /**
     * Get application instance or resolve from container
     */
    function app(?string $abstract = null): mixed
    {
        $container = \HybridPHP\Core\Container::getInstance();
        
        if ($abstract === null) {
            return $container;
        }
        
        return $container->get($abstract);
    }
}

if (!function_exists('base_path')) {
    /**
     * Get base path
     */
    function base_path(string $path = ''): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        return $basePath . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get storage path
     */
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('config_path')) {
    /**
     * Get config path
     */
    function config_path(string $path = ''): string
    {
        return base_path('config' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('database_path')) {
    /**
     * Get database path
     */
    function database_path(string $path = ''): string
    {
        return base_path('database' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('public_path')) {
    /**
     * Get public path
     */
    function public_path(string $path = ''): string
    {
        return base_path('public' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }
}

if (!function_exists('logger')) {
    /**
     * Get logger instance
     */
    function logger(): \Psr\Log\LoggerInterface
    {
        return app(\Psr\Log\LoggerInterface::class);
    }
}

if (!function_exists('cache')) {
    /**
     * Get cache instance
     */
    function cache(?string $store = null): \HybridPHP\Core\Cache\CacheInterface
    {
        $manager = app(\HybridPHP\Core\Cache\CacheManager::class);
        return $store ? $manager->store($store) : $manager->store();
    }
}

if (!function_exists('response')) {
    /**
     * Create response
     */
    function response(): \HybridPHP\Core\Http\ResponseFactory
    {
        return new \HybridPHP\Core\Http\ResponseFactory();
    }
}

if (!function_exists('dd')) {
    /**
     * Dump and die
     */
    function dd(mixed ...$vars): never
    {
        foreach ($vars as $var) {
            var_dump($var);
        }
        exit(1);
    }
}

if (!function_exists('dump')) {
    /**
     * Dump variables
     */
    function dump(mixed ...$vars): void
    {
        foreach ($vars as $var) {
            var_dump($var);
        }
    }
}

if (!function_exists('now')) {
    /**
     * Get current datetime
     */
    function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}

if (!function_exists('uuid')) {
    /**
     * Generate UUID
     */
    function uuid(): string
    {
        return \Ramsey\Uuid\Uuid::uuid4()->toString();
    }
}
