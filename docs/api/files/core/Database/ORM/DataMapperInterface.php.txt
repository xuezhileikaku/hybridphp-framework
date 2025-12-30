<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\ORM;

use Amp\Future;

/**
 * DataMapper interface for ORM
 */
interface DataMapperInterface
{
    /**
     * Find entity by ID
     */
    public function findById($id): Future;

    /**
     * Find entities by criteria
     */
    public function findBy(array $criteria, array $orderBy = [], int $limit = null, int $offset = null): Future;

    /**
     * Find one entity by criteria
     */
    public function findOneBy(array $criteria): Future;

    /**
     * Find all entities
     */
    public function findAll(array $orderBy = [], int $limit = null, int $offset = null): Future;

    /**
     * Save entity (insert or update)
     */
    public function save(object $entity): Future;

    /**
     * Delete entity
     */
    public function delete(object $entity): Future;

    /**
     * Delete entities by criteria
     */
    public function deleteBy(array $criteria): Future;

    /**
     * Count entities by criteria
     */
    public function count(array $criteria = []): Future;

    /**
     * Check if entity exists
     */
    public function exists(array $criteria): Future;

    /**
     * Begin transaction
     */
    public function beginTransaction(): Future;

    /**
     * Commit transaction
     */
    public function commit(): Future;

    /**
     * Rollback transaction
     */
    public function rollback(): Future;

    /**
     * Execute in transaction
     */
    public function transaction(callable $callback): Future;
}