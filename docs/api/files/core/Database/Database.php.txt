<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database;

use Amp\Mysql\MysqlConnection;
use Amp\Mysql\MysqlTransaction;
use Amp\Future;
use Psr\Log\LoggerInterface;
use function Amp\async;

/**
 * Async database implementation with connection pooling
 */
class Database implements DatabaseInterface
{
    private ConnectionPoolInterface $connectionPool;
    private LoggerInterface $logger;
    private array $config;
    private ?MysqlTransaction $currentTransaction = null;

    public function __construct(ConnectionPoolInterface $connectionPool, LoggerInterface $logger, array $config = [])
    {
        $this->connectionPool = $connectionPool;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function query(string $sql, array $params = []): Future
    {
        return async(function () use ($sql, $params) {
            $startTime = microtime(true);
            $connection = null;

            try {
                $connection = $this->getConnectionForQuery()->await();

                $this->logger->debug('Executing query', [
                    'sql' => $sql,
                    'params' => $params,
                ]);

                if (empty($params)) {
                    $result = $connection->query($sql)->await();
                } else {
                    $statement = $connection->prepare($sql)->await();
                    $result = $statement->execute($params)->await();
                }

                $queryTime = microtime(true) - $startTime;
                $this->connectionPool->updateQueryStats($queryTime, true);

                $this->logger->debug('Query executed successfully', [
                    'query_time' => $queryTime,
                    'affected_rows' => $result->getRowCount() ?? 0,
                ]);

                return $result;
            } catch (\Throwable $e) {
                $queryTime = microtime(true) - $startTime;
                $this->connectionPool->updateQueryStats($queryTime, false);

                $this->logger->error('Query execution failed', [
                    'sql' => $sql,
                    'params' => $params,
                    'error' => $e->getMessage(),
                    'query_time' => $queryTime,
                ]);

                throw $e;
            } finally {
                if ($connection && !$this->currentTransaction) {
                    $this->connectionPool->releaseConnection($connection);
                }
            }
        });
    }

    public function execute(string $sql, array $params = []): Future
    {
        return async(function () use ($sql, $params) {
            $startTime = microtime(true);
            $connection = null;

            try {
                $connection = $this->getConnectionForQuery()->await();

                $this->logger->debug('Executing statement', [
                    'sql' => $sql,
                    'params' => $params,
                ]);

                if (empty($params)) {
                    $result = $connection->execute($sql)->await();
                } else {
                    $statement = $connection->prepare($sql)->await();
                    $result = $statement->execute($params)->await();
                }

                $queryTime = microtime(true) - $startTime;
                $this->connectionPool->updateQueryStats($queryTime, true);

                $this->logger->debug('Statement executed successfully', [
                    'query_time' => $queryTime,
                    'affected_rows' => $result->getRowCount() ?? 0,
                ]);

                return $result;
            } catch (\Throwable $e) {
                $queryTime = microtime(true) - $startTime;
                $this->connectionPool->updateQueryStats($queryTime, false);

                $this->logger->error('Statement execution failed', [
                    'sql' => $sql,
                    'params' => $params,
                    'error' => $e->getMessage(),
                    'query_time' => $queryTime,
                ]);

                throw $e;
            } finally {
                if ($connection && !$this->currentTransaction) {
                    $this->connectionPool->releaseConnection($connection);
                }
            }
        });
    }

    public function beginTransaction(): Future
    {
        return async(function () {
            if ($this->currentTransaction) {
                throw new \RuntimeException('Transaction already in progress');
            }

            try {
                $connection = $this->connectionPool->getConnection()->await();
                $this->currentTransaction = $connection->beginTransaction()->await();

                $this->logger->debug('Transaction started');

                return $this->currentTransaction;
            } catch (\Throwable $e) {
                $this->logger->error('Failed to start transaction', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    public function commit(): Future
    {
        return async(function () {
            if (!$this->currentTransaction) {
                throw new \RuntimeException('No active transaction to commit');
            }

            try {
                $this->currentTransaction->commit()->await();
                $connection = $this->currentTransaction->getConnection();
                $this->connectionPool->releaseConnection($connection);
                $this->currentTransaction = null;

                $this->logger->debug('Transaction committed');
            } catch (\Throwable $e) {
                $this->logger->error('Failed to commit transaction', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    public function rollback(): Future
    {
        return async(function () {
            if (!$this->currentTransaction) {
                throw new \RuntimeException('No active transaction to rollback');
            }

            try {
                $this->currentTransaction->rollback()->await();
                $connection = $this->currentTransaction->getConnection();
                $this->connectionPool->releaseConnection($connection);
                $this->currentTransaction = null;

                $this->logger->debug('Transaction rolled back');
            } catch (\Throwable $e) {
                $this->logger->error('Failed to rollback transaction', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }

    public function transaction(callable $callback): Future
    {
        return async(function () use ($callback) {
            $this->beginTransaction()->await();

            try {
                $result = $callback($this)->await();
                $this->commit()->await();
                return $result;
            } catch (\Throwable $e) {
                $this->rollback()->await();
                throw $e;
            }
        });
    }

    public function getStats(): array
    {
        return $this->connectionPool->getStats();
    }

    public function healthCheck(): Future
    {
        return $this->connectionPool->healthCheck();
    }

    private function getConnectionForQuery(): Future
    {
        if ($this->currentTransaction) {
            return async(fn() => $this->currentTransaction->getConnection());
        }

        return $this->connectionPool->getConnection();
    }

    public function setIsolationLevel(string $level): Future
    {
        $validLevels = [
            'READ UNCOMMITTED',
            'READ COMMITTED',
            'REPEATABLE READ',
            'SERIALIZABLE'
        ];

        if (!in_array($level, $validLevels)) {
            return async(fn() => throw new \InvalidArgumentException("Invalid isolation level: $level"));
        }

        return $this->execute("SET SESSION TRANSACTION ISOLATION LEVEL $level");
    }

    public function getConnectionPool(): ConnectionPoolInterface
    {
        return $this->connectionPool;
    }
}
