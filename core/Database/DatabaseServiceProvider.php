<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database;

use HybridPHP\Core\Container;
use Psr\Log\LoggerInterface;

/**
 * Database service provider for dependency injection
 */
class DatabaseServiceProvider
{
    public static function register(Container $container): void
    {
        // Register database manager as singleton
        $container->singleton(DatabaseManager::class, function () use ($container) {
            $config = $container->get('config')->get('database', []);
            $logger = $container->get(LoggerInterface::class);
            
            return new DatabaseManager($config, $logger);
        });

        // Register default database connection
        $container->singleton(DatabaseInterface::class, function () use ($container) {
            $manager = $container->get(DatabaseManager::class);
            return $manager->connection();
        });

        // Register connection pool interface
        $container->singleton(ConnectionPoolInterface::class, function () use ($container) {
            $database = $container->get(DatabaseInterface::class);
            if ($database instanceof Database) {
                return $database->getConnectionPool();
            }
            
            throw new \RuntimeException('Unable to resolve connection pool');
        });

        // Register database manager alias
        $container->alias('db', DatabaseManager::class);
        $container->alias('database', DatabaseInterface::class);
    }
}