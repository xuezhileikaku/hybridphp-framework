<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnection;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Future;


use Psr\Log\LoggerInterface;
use function Amp\async;
use function Amp\delay;

/**
 * Async database connection pool implementation
 */
class ConnectionPool implements ConnectionPoolInterface
{
    private MysqlConnectionPool $pool;
    private array $config;
    private LoggerInterface $logger;
    private array $stats = [
        'total_connections' => 0,
        'active_connections' => 0,
        'idle_connections' => 0,
        'failed_connections' => 0,
        'total_queries' => 0,
        'failed_queries' => 0,
        'avg_query_time' => 0,
        'last_health_check' => null,
        'created_at' => null,
    ];
    private array $connections = [];
    private bool $closed = false;

    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->stats['created_at'] = time();
        
        $this->initializePool();
    }

    private function initializePool(): void
    {
        $mysqlConfig = new MysqlConfig(
            host: $this->config['host'] ?? 'localhost',
            port: $this->config['port'] ?? 3306,
            user: $this->config['username'] ?? 'root',
            password: $this->config['password'] ?? '',
            database: $this->config['database'] ?? '',
            charset: $this->config['charset'] ?? 'utf8mb4',
            collate: $this->config['collation'] ?? 'utf8mb4_unicode_ci'
        );

        $poolConfig = $this->config['pool'] ?? [];
        
        $this->pool = new MysqlConnectionPool(
            $mysqlConfig,
            $poolConfig['max'] ?? 50,
            $poolConfig['idle_timeout'] ?? 60
        );

        $this->logger->info('Database connection pool initialized', [
            'host' => $this->config['host'] ?? 'localhost',
            'database' => $this->config['database'] ?? '',
            'max_connections' => $poolConfig['max'] ?? 50,
        ]);
    }

    public function getConnection(): Future
    {
        if ($this->closed) {
            return async(fn() => throw new \RuntimeException('Connection pool is closed'));
        }

        return async(function () {
            try {
                $connection = $this->pool->getConnection()->await();
                $this->stats['active_connections']++;
                $this->stats['total_connections']++;

                $connectionId = spl_object_id($connection);
                $this->connections[$connectionId] = [
                    'connection' => $connection,
                    'acquired_at' => microtime(true),
                    'last_used' => microtime(true),
                ];

                $this->logger->debug('Connection acquired from pool', [
                    'connection_id' => $connectionId,
                    'active_connections' => $this->stats['active_connections'],
                ]);

                return $connection;
            } catch (\Throwable $e) {
                $this->stats['failed_connections']++;
                $this->logger->error('Failed to acquire connection from pool', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }
        });
    }

    public function releaseConnection($connection): void
    {
        if (!$connection instanceof MysqlConnection) {
            return;
        }

        $connectionId = spl_object_id($connection);
        
        if (isset($this->connections[$connectionId])) {
            $connectionInfo = $this->connections[$connectionId];
            $usageTime = microtime(true) - $connectionInfo['acquired_at'];
            
            unset($this->connections[$connectionId]);
            $this->stats['active_connections']--;
            $this->stats['idle_connections']++;

            $this->logger->debug('Connection released to pool', [
                'connection_id' => $connectionId,
                'usage_time' => $usageTime,
                'active_connections' => $this->stats['active_connections'],
            ]);
        }

        // The connection will be automatically returned to the pool
        // when it goes out of scope in amphp/mysql
    }

    public function getStats(): array
    {
        $this->stats['idle_connections'] = $this->getTotalCount() - $this->getActiveCount();
        return $this->stats;
    }

    public function close(): Future
    {
        return async(function () {
            if ($this->closed) {
                return;
            }

            $this->closed = true;

            try {
                $this->pool->close()->await();
                $this->connections = [];
                $this->stats['active_connections'] = 0;
                $this->stats['idle_connections'] = 0;

                $this->logger->info('Connection pool closed successfully');
            } catch (\Throwable $e) {
                $this->logger->error('Error closing connection pool', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    public function healthCheck(): Future
    {
        return async(function () {
            try {
                $connection = $this->getConnection()->await();
                $result = $connection->query('SELECT 1 as health_check')->await();
                $this->releaseConnection($connection);

                $this->stats['last_health_check'] = time();

                $this->logger->debug('Health check passed');
                return true;
            } catch (\Throwable $e) {
                $this->logger->warning('Health check failed', [
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        });
    }

    public function getActiveCount(): int
    {
        return count($this->connections);
    }

    public function getIdleCount(): int
    {
        return max(0, $this->getTotalCount() - $this->getActiveCount());
    }

    public function getTotalCount(): int
    {
        return $this->pool->getConnectionCount();
    }

    /**
     * Update query statistics
     */
    public function updateQueryStats(float $queryTime, bool $success = true): void
    {
        $this->stats['total_queries']++;
        
        if (!$success) {
            $this->stats['failed_queries']++;
        }

        // Calculate rolling average query time
        $totalTime = $this->stats['avg_query_time'] * ($this->stats['total_queries'] - 1);
        $this->stats['avg_query_time'] = ($totalTime + $queryTime) / $this->stats['total_queries'];
    }

    /**
     * Start periodic health checks
     */
    public function startHealthCheckTimer(int $intervalSeconds = 30): void
    {
        async(function () use ($intervalSeconds) {
            while (!$this->closed) {
                delay($intervalSeconds);

                if (!$this->closed) {
                    $this->healthCheck()->await();
                }
            }
        });
    }
}