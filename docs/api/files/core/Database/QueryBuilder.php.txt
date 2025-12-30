<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database;

use Amp\Future;
use function Amp\async;

/**
 * Simple async query builder
 */
class QueryBuilder
{
    private DatabaseInterface $database;
    private string $table = '';
    private array $select = ['*'];
    private array $where = [];
    private array $joins = [];
    private array $orderBy = [];
    private array $groupBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $bindings = [];

    public function __construct(DatabaseInterface $database)
    {
        $this->database = $database;
    }

    /**
     * Set the table to query
     */
    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Set the columns to select
     */
    public function select(array $columns = ['*']): self
    {
        $this->select = $columns;
        return $this;
    }

    /**
     * Add a where clause
     */
    public function where(string $column, string $operator, $value): self
    {
        $this->where[] = [
            'type' => 'where',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];
        return $this;
    }

    /**
     * Add an OR where clause
     */
    public function orWhere(string $column, string $operator, $value): self
    {
        $this->where[] = [
            'type' => 'or_where',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];
        return $this;
    }

    /**
     * Add a where in clause
     */
    public function whereIn(string $column, array $values): self
    {
        $this->where[] = [
            'type' => 'where_in',
            'column' => $column,
            'values' => $values,
        ];
        return $this;
    }

    /**
     * Add a join clause
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $this->joins[] = [
            'type' => $type,
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];
        return $this;
    }

    /**
     * Add a left join clause
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /**
     * Add an order by clause
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = [
            'column' => $column,
            'direction' => strtoupper($direction),
        ];
        return $this;
    }

    /**
     * Add a group by clause
     */
    public function groupBy(string $column): self
    {
        $this->groupBy[] = $column;
        return $this;
    }

    /**
     * Set the limit
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set the offset
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Execute the query and get results
     */
    public function get(): Future
    {
        return async(function () {
            $sql = $this->buildSelectSql();
            $result = $this->database->query($sql, $this->bindings)->await();

            $rows = [];
            while ($result->advance()->await()) {
                $rows[] = $result->getCurrent();
            }

            return $rows;
        });
    }

    /**
     * Get the first result
     */
    public function first(): Future
    {
        return async(function () {
            $originalLimit = $this->limit;
            $this->limit = 1;

            $results = $this->get()->await();

            $this->limit = $originalLimit;

            return empty($results) ? null : $results[0];
        });
    }

    /**
     * Get count of records
     */
    public function count(): Future
    {
        return async(function () {
            $originalSelect = $this->select;
            $this->select = ['COUNT(*) as count'];

            $result = $this->first()->await();

            $this->select = $originalSelect;

            return $result ? (int) $result['count'] : 0;
        });
    }

    /**
     * Insert a record
     */
    public function insert(array $data): Future
    {
        return async(function () use ($data) {
            $columns = array_keys($data);
            $placeholders = array_fill(0, count($data), '?');

            $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

            return $this->database->execute($sql, array_values($data))->await();
        });
    }

    /**
     * Update records
     */
    public function update(array $data): Future
    {
        return async(function () use ($data) {
            $setParts = [];
            $bindings = [];

            foreach ($data as $column => $value) {
                $setParts[] = "$column = ?";
                $bindings[] = $value;
            }

            $sql = "UPDATE {$this->table} SET " . implode(', ', $setParts);

            if (!empty($this->where)) {
                $whereClause = $this->buildWhereClause();
                $sql .= " WHERE " . $whereClause['sql'];
                $bindings = array_merge($bindings, $whereClause['bindings']);
            }

            return $this->database->execute($sql, $bindings)->await();
        });
    }

    /**
     * Delete records
     */
    public function delete(): Future
    {
        return async(function () {
            $sql = "DELETE FROM {$this->table}";
            $bindings = [];

            if (!empty($this->where)) {
                $whereClause = $this->buildWhereClause();
                $sql .= " WHERE " . $whereClause['sql'];
                $bindings = $whereClause['bindings'];
            }

            return $this->database->execute($sql, $bindings)->await();
        });
    }

    /**
     * Build the SELECT SQL
     */
    private function buildSelectSql(): string
    {
        $sql = "SELECT " . implode(', ', $this->select) . " FROM {$this->table}";
        
        // Add joins
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['first']} {$join['operator']} {$join['second']}";
        }
        
        // Add where clause
        if (!empty($this->where)) {
            $whereClause = $this->buildWhereClause();
            $sql .= " WHERE " . $whereClause['sql'];
            $this->bindings = $whereClause['bindings'];
        }
        
        // Add group by
        if (!empty($this->groupBy)) {
            $sql .= " GROUP BY " . implode(', ', $this->groupBy);
        }
        
        // Add order by
        if (!empty($this->orderBy)) {
            $orderParts = [];
            foreach ($this->orderBy as $order) {
                $orderParts[] = "{$order['column']} {$order['direction']}";
            }
            $sql .= " ORDER BY " . implode(', ', $orderParts);
        }
        
        // Add limit and offset
        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
            
            if ($this->offset !== null) {
                $sql .= " OFFSET {$this->offset}";
            }
        }
        
        return $sql;
    }

    /**
     * Build the WHERE clause
     */
    private function buildWhereClause(): array
    {
        $sql = '';
        $bindings = [];
        $first = true;
        
        foreach ($this->where as $condition) {
            if (!$first) {
                $sql .= $condition['type'] === 'or_where' ? ' OR ' : ' AND ';
            }
            $first = false;
            
            if ($condition['type'] === 'where_in') {
                $placeholders = array_fill(0, count($condition['values']), '?');
                $sql .= "{$condition['column']} IN (" . implode(', ', $placeholders) . ")";
                $bindings = array_merge($bindings, $condition['values']);
            } else {
                $sql .= "{$condition['column']} {$condition['operator']} ?";
                $bindings[] = $condition['value'];
            }
        }
        
        return ['sql' => $sql, 'bindings' => $bindings];
    }

    /**
     * Get the raw SQL query
     */
    public function toSql(): string
    {
        return $this->buildSelectSql();
    }

    /**
     * Get the bindings
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }
}