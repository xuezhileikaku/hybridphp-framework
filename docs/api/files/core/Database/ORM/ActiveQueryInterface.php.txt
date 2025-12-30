<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\ORM;

use Amp\Future;

/**
 * ActiveQuery interface for chainable queries
 */
interface ActiveQueryInterface
{
    /**
     * Set the model class
     */
    public function modelClass(string $class): self;

    /**
     * Add a where condition
     */
    public function where(array $condition): self;

    /**
     * Add an AND where condition
     */
    public function andWhere(array $condition): self;

    /**
     * Add an OR where condition
     */
    public function orWhere(array $condition): self;

    /**
     * Add order by
     */
    public function orderBy(array $columns): self;

    /**
     * Set limit
     */
    public function limit(int $limit): self;

    /**
     * Set offset
     */
    public function offset(int $offset): self;

    /**
     * Add a join
     */
    public function join(string $type, string $table, string $on = ''): self;

    /**
     * Add an inner join
     */
    public function innerJoin(string $table, string $on = ''): self;

    /**
     * Add a left join
     */
    public function leftJoin(string $table, string $on = ''): self;

    /**
     * Add a right join
     */
    public function rightJoin(string $table, string $on = ''): self;

    /**
     * Set select columns
     */
    public function select(array $columns): self;

    /**
     * Add group by
     */
    public function groupBy(array $columns): self;

    /**
     * Add having condition
     */
    public function having(array $condition): self;

    /**
     * Include relations
     */
    public function with(array $relations): self;

    /**
     * Execute query and return all results
     */
    public function all(): Future;

    /**
     * Execute query and return one result
     */
    public function one(): Future;

    /**
     * Execute query and return scalar value
     */
    public function scalar(): Future;

    /**
     * Count records
     */
    public function count(): Future;

    /**
     * Check if records exist
     */
    public function exists(): Future;

    /**
     * Get the SQL query
     */
    public function createCommand(): array;
}