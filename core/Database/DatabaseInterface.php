<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database;

use Amp\Future;

/**
 * Database interface for async operations
 */
interface DatabaseInterface
{
    /**
     * Execute a query and return results
     */
    public function query(string $sql, array $params = []): Future;

    /**
     * Execute a statement (INSERT, UPDATE, DELETE)
     */
    public function execute(string $sql, array $params = []): Future;

    /**
     * Begin a transaction
     */
    public function beginTransaction(): Future;

    /**
     * Commit a transaction
     */
    public function commit(): Future;

    /**
     * Rollback a transaction
     */
    public function rollback(): Future;

    /**
     * Execute within a transaction
     */
    public function transaction(callable $callback): Future;

    /**
     * Get connection statistics
     */
    public function getStats(): array;

    /**
     * Check connection health
     */
    public function healthCheck(): Future;
}