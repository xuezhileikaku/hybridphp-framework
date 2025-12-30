<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database;

use Amp\Future;

/**
 * Connection pool interface
 */
interface ConnectionPoolInterface
{
    /**
     * Get a connection from the pool
     */
    public function getConnection(): Future;

    /**
     * Release a connection back to the pool
     */
    public function releaseConnection($connection): void;

    /**
     * Get pool statistics
     */
    public function getStats(): array;

    /**
     * Close all connections in the pool
     */
    public function close(): Future;

    /**
     * Perform health check on all connections
     */
    public function healthCheck(): Future;

    /**
     * Get active connection count
     */
    public function getActiveCount(): int;

    /**
     * Get idle connection count
     */
    public function getIdleCount(): int;

    /**
     * Get total connection count
     */
    public function getTotalCount(): int;

    /**
     * Update query statistics
     */
    public function updateQueryStats(float $queryTime, bool $success): void;
}