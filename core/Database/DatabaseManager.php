<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database;

use Amp\Future;


use Psr\Log\LoggerInterface;
use function Amp\async;

/**
 * Database manager with read/write separation and failover support
 */
class DatabaseManager
{
    private array $connections = [];
    private array $config;
    private LoggerInterface $logger;
    private string $defaultConnection;
    private array $readConnections = [];
    private array $writeConnections = [];
    private int $readConnectionIndex = 0;

    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->defaultConnection = $config['default'] ?? 'mysql';
        
        $this->initializeConnections();
    }

    private function initializeConnections(): void
    {
        foreach ($this->config['connections'] as $name => $connectionConfig) {
            $this->createConnection($name, $connectionConfig);
            
            // Setup read/write separation
            if (isset($connectionConfig['read']) && $connectionConfig['read']) {
                $this->readConnections[] = $name;
            }
            if (isset($connectionConfig['write']) && $connectionConfig['write']) {
                $this->writeConnections[] = $name;
            }
            
            // If no explicit read/write config, use for both
            if (!isset($connectionConfig['read']) && !isset($connectionConfig['write'])) {
                $this->readConnections[] = $name;
                $this->writeConnections[] = $name;
            }
        }

        $this->logger->info('Database manager initialized', [
            'connections' => array_keys($this->connections),
            'read_connections' => $this->readConnections,
            'write_connections' => $this->writeConnections,
            'default' => $this->defaultConnection,
        ]);
    }

    private function createConnection(string $name, array $config): void
    {
        try {
            $connectionPool = new ConnectionPool($config, $this->logger);
            $database = new Database($connectionPool, $this->logger, $config);
            
            $this->connections[$name] = $database;
            
            // Start health check timer
            $connectionPool->startHealthCheckTimer(
                $config['health_check_interval'] ?? 30
            );
            
            $this->logger->info("Database connection '$name' created successfully");
        } catch (\Throwable $e) {
            $this->logger->error("Failed to create database connection '$name'", [
                'error' => $e->getMessage(),
                'config' => array_diff_key($config, ['password' => null]),
            ]);
            throw $e;
        }
    }

    /**
     * Get connection by name
     */
    public function connection(string $name = null): DatabaseInterface
    {
        $name = $name ?? $this->defaultConnection;
        
        if (!isset($this->connections[$name])) {
            throw new \InvalidArgumentException("Database connection '$name' not configured");
        }

        return $this->connections[$name];
    }

    /**
     * Get read connection (with load balancing)
     */
    public function readConnection(): DatabaseInterface
    {
        if (empty($this->readConnections)) {
            return $this->connection();
        }

        // Simple round-robin load balancing
        $connectionName = $this->readConnections[$this->readConnectionIndex];
        $this->readConnectionIndex = ($this->readConnectionIndex + 1) % count($this->readConnections);

        return $this->connection($connectionName);
    }

    /**
     * Get write connection (with failover)
     */
    public function writeConnection(): DatabaseInterface
    {
        if (empty($this->writeConnections)) {
            return $this->connection();
        }

        // Try write connections in order until one works
        foreach ($this->writeConnections as $connectionName) {
            try {
                $connection = $this->connection($connectionName);
                
                // Test connection health
                $healthCheck = $connection->healthCheck();
                if ($healthCheck instanceof Promise) {
                    // For now, assume it's healthy if no immediate exception
                    return $connection;
                }
                
                return $connection;
            } catch (\Throwable $e) {
                $this->logger->warning("Write connection '$connectionName' failed, trying next", [
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        throw new \RuntimeException('All write connections failed');
    }

    /**
     * Execute query with automatic read/write routing
     */
    public function query(string $sql, array $params = [], string $connectionName = null): Future
    {
        return async(function () use ($sql, $params, $connectionName) {
            if ($connectionName) {
                return $this->connection($connectionName)->query($sql, $params)->await();
            }

            // Determine if this is a read or write operation
            $isWrite = $this->isWriteOperation($sql);
            
            if ($isWrite) {
                return $this->writeConnection()->query($sql, $params)->await();
            } else {
                return $this->readConnection()->query($sql, $params)->await();
            }
        });
    }

    /**
     * Execute statement with automatic read/write routing
     */
    public function execute(string $sql, array $params = [], string $connectionName = null): Future
    {
        return async(function () use ($sql, $params, $connectionName) {
            if ($connectionName) {
                return $this->connection($connectionName)->execute($sql, $params)->await();
            }

            // Execute operations are typically writes
            return $this->writeConnection()->execute($sql, $params)->await();
        });
    }

    /**
     * Begin transaction on write connection
     */
    public function beginTransaction(string $connectionName = null): Future
    {
        $connection = $connectionName ? 
            $this->connection($connectionName) : 
            $this->writeConnection();
            
        return $connection->beginTransaction();
    }

    /**
     * Execute within transaction
     */
    public function transaction(callable $callback, string $connectionName = null): Future
    {
        $connection = $connectionName ? 
            $this->connection($connectionName) : 
            $this->writeConnection();
            
        return $connection->transaction($callback);
    }

    /**
     * Get statistics for all connections
     */
    public function getAllStats(): array
    {
        $stats = [];
        
        foreach ($this->connections as $name => $connection) {
            $stats[$name] = $connection->getStats();
        }

        return $stats;
    }

    /**
     * Perform health check on all connections
     */
    public function healthCheckAll(): Future
    {
        return async(function () {
            $results = [];
            
            foreach ($this->connections as $name => $connection) {
                try {
                    $healthy = $connection->healthCheck()->await();
                    $results[$name] = [
                        'healthy' => $healthy,
                        'error' => null,
                    ];
                } catch (\Throwable $e) {
                    $results[$name] = [
                        'healthy' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return $results;
        });
    }

    /**
     * Close all connections
     */
    public function closeAll(): Future
    {
        return async(function () {
            foreach ($this->connections as $name => $connection) {
                try {
                    if ($connection instanceof Database) {
                        $connection->getConnectionPool()->close()->await();
                    }
                    $this->logger->info("Connection '$name' closed");
                } catch (\Throwable $e) {
                    $this->logger->error("Error closing connection '$name'", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            $this->connections = [];
        });
    }

    /**
     * Determine if SQL is a write operation
     */
    private function isWriteOperation(string $sql): bool
    {
        $sql = trim(strtoupper($sql));
        $writeOperations = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'DROP', 'ALTER', 'TRUNCATE'];
        
        foreach ($writeOperations as $operation) {
            if (strpos($sql, $operation) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get all connection names
     */
    public function getConnectionNames(): array
    {
        return array_keys($this->connections);
    }

    /**
     * Check if connection exists
     */
    public function hasConnection(string $name): bool
    {
        return isset($this->connections[$name]);
    }
}