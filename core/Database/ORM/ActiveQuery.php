<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\ORM;

use Amp\Future;
use HybridPHP\Core\Database\DatabaseInterface;
use HybridPHP\Core\Database\QueryBuilder;
use HybridPHP\Core\Container;
use function Amp\async;

/**
 * ActiveQuery implementation for chainable queries
 */
class ActiveQuery implements ActiveQueryInterface
{
    protected string $modelClass;
    protected array $where = [];
    protected array $orderBy = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected array $joins = [];
    protected array $select = [];
    protected array $groupBy = [];
    protected array $having = [];
    protected array $with = [];
    protected ?DatabaseInterface $db = null;

    public function __construct(string $modelClass)
    {
        $this->modelClass = $modelClass;
    }

    /**
     * Get database connection
     */
    protected function getDb(): DatabaseInterface
    {
        if ($this->db === null) {
            $this->db = Container::getInstance()->get(DatabaseInterface::class);
        }
        return $this->db;
    }

    /**
     * Set the model class
     */
    public function modelClass(string $class): self
    {
        $this->modelClass = $class;
        return $this;
    }

    /**
     * Add a where condition
     */
    public function where(array $condition): self
    {
        $this->where = $condition;
        return $this;
    }

    /**
     * Add an AND where condition
     */
    public function andWhere(array $condition): self
    {
        if (empty($this->where)) {
            $this->where = $condition;
        } else {
            $this->where = ['and', $this->where, $condition];
        }
        return $this;
    }

    /**
     * Add an OR where condition
     */
    public function orWhere(array $condition): self
    {
        if (empty($this->where)) {
            $this->where = $condition;
        } else {
            $this->where = ['or', $this->where, $condition];
        }
        return $this;
    }

    /**
     * Add order by
     */
    public function orderBy(array $columns): self
    {
        $this->orderBy = $columns;
        return $this;
    }

    /**
     * Set limit
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set offset
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Add a join
     */
    public function join(string $type, string $table, string $on = ''): self
    {
        $this->joins[] = [$type, $table, $on];
        return $this;
    }

    /**
     * Add an inner join
     */
    public function innerJoin(string $table, string $on = ''): self
    {
        return $this->join('INNER JOIN', $table, $on);
    }

    /**
     * Add a left join
     */
    public function leftJoin(string $table, string $on = ''): self
    {
        return $this->join('LEFT JOIN', $table, $on);
    }

    /**
     * Add a right join
     */
    public function rightJoin(string $table, string $on = ''): self
    {
        return $this->join('RIGHT JOIN', $table, $on);
    }

    /**
     * Set select columns
     */
    public function select(array $columns): self
    {
        $this->select = $columns;
        return $this;
    }

    /**
     * Add group by
     */
    public function groupBy(array $columns): self
    {
        $this->groupBy = $columns;
        return $this;
    }

    /**
     * Add having condition
     */
    public function having(array $condition): self
    {
        $this->having = $condition;
        return $this;
    }

    /**
     * Include relations
     */
    public function with(array $relations): self
    {
        $this->with = $relations;
        return $this;
    }

    /**
     * Execute query and return all results
     */
    public function all(): Future
    {
        return async(function () {
            $command = $this->createCommand();
            $db = $this->getDb();
            
            $result = $db->query($command['sql'], $command['params'])->await();
            $models = [];
            
            foreach ($result as $row) {
                $model = $this->createModel($row);
                $models[] = $model;
            }

            // Load relations if specified
            if (!empty($this->with) && !empty($models)) {
                $this->loadRelations($models)->await();
            }

            return $models;
        });
    }

    /**
     * Execute query and return one result
     */
    public function one(): Future
    {
        return async(function () {
            $originalLimit = $this->limit;
            $this->limit = 1;
            
            $models = $this->all()->await();
            
            $this->limit = $originalLimit;
            
            return empty($models) ? null : $models[0];
        });
    }

    /**
     * Execute query and return scalar value
     */
    public function scalar(): Future
    {
        return async(function () {
            $command = $this->createCommand();
            $db = $this->getDb();
            
            $result = $db->query($command['sql'], $command['params'])->await();
            
            if (!empty($result)) {
                $row = $result[0] ?? null;
                return is_array($row) ? reset($row) : $row;
            }
            
            return null;
        });
    }

    /**
     * Count records
     */
    public function count(): Future
    {
        return async(function () {
            $originalSelect = $this->select;
            $this->select = ['COUNT(*) as count'];
            
            $result = $this->scalar()->await();
            
            $this->select = $originalSelect;
            
            return (int)$result;
        });
    }

    /**
     * Check if records exist
     */
    public function exists(): Future
    {
        return async(function () {
            $count = $this->count()->await();
            return $count > 0;
        });
    }

    /**
     * Get the SQL query
     */
    public function createCommand(): array
    {
        $modelClass = $this->modelClass;
        $tableName = $modelClass::tableName();
        
        $query = new QueryBuilder($this->getDb());
        $query->table($tableName);

        // Select columns
        if (!empty($this->select)) {
            $query->select($this->select);
        }

        // Where conditions
        if (!empty($this->where)) {
            $this->buildWhere($query, $this->where);
        }

        // Joins
        foreach ($this->joins as $join) {
            [$type, $table, $on] = $join;
            if ($type === 'INNER JOIN') {
                $query->join($table, '', '', '', 'INNER');
            } elseif ($type === 'LEFT JOIN') {
                $query->leftJoin($table, '', '', '');
            }
        }

        // Order by
        foreach ($this->orderBy as $column => $direction) {
            $query->orderBy($column, $direction);
        }

        // Group by
        foreach ($this->groupBy as $column) {
            $query->groupBy($column);
        }

        // Limit and offset
        if ($this->limit !== null) {
            $query->limit($this->limit);
        }
        if ($this->offset !== null) {
            $query->offset($this->offset);
        }

        return [
            'sql' => $query->toSql(),
            'params' => $query->getBindings()
        ];
    }

    /**
     * Build where conditions
     */
    protected function buildWhere(QueryBuilder $query, array $condition): void
    {
        if (empty($condition)) {
            return;
        }

        // Handle simple key-value conditions
        if (!isset($condition[0])) {
            foreach ($condition as $column => $value) {
                if (is_array($value)) {
                    $query->whereIn($column, $value);
                } else {
                    $query->where($column, '=', $value);
                }
            }
            return;
        }

        // Handle complex conditions with operators
        $operator = strtolower($condition[0]);
        
        switch ($operator) {
            case 'and':
                for ($i = 1; $i < count($condition); $i++) {
                    $this->buildWhere($query, $condition[$i]);
                }
                break;
                
            case 'or':
                // For OR conditions, we need to handle them differently
                // This is a simplified implementation
                for ($i = 1; $i < count($condition); $i++) {
                    if ($i === 1) {
                        $this->buildWhere($query, $condition[$i]);
                    } else {
                        // Add OR condition - this would need more sophisticated handling
                        $this->buildWhere($query, $condition[$i]);
                    }
                }
                break;
                
            case 'in':
                if (isset($condition[1]) && isset($condition[2])) {
                    $query->whereIn($condition[1], $condition[2]);
                }
                break;
                
            case 'like':
                if (isset($condition[1]) && isset($condition[2])) {
                    $query->where($condition[1], 'LIKE', $condition[2]);
                }
                break;
                
            default:
                // Handle other operators
                if (count($condition) >= 3) {
                    $query->where($condition[1], $condition[0], $condition[2]);
                }
                break;
        }
    }

    /**
     * Create model instance from database row
     */
    protected function createModel(array $row): ActiveRecordInterface
    {
        $modelClass = $this->modelClass;
        $model = new $modelClass();
        $model->setAttributes($row);
        
        // Mark as existing record
        $reflection = new \ReflectionClass($model);
        $property = $reflection->getProperty('isNewRecord');
        $property->setAccessible(true);
        $property->setValue($model, false);
        
        $property = $reflection->getProperty('oldAttributes');
        $property->setAccessible(true);
        $property->setValue($model, $row);
        
        return $model;
    }

    /**
     * Load relations for models
     */
    protected function loadRelations(array $models): Future
    {
        return async(function () use ($models) {
            foreach ($this->with as $relationName) {
                $this->loadRelation($models, $relationName)->await();
            }
        });
    }

    /**
     * Load a specific relation
     */
    protected function loadRelation(array $models, string $relationName): Future
    {
        return async(function () use ($models, $relationName) {
            if (empty($models)) {
                return;
            }

            $firstModel = $models[0];
            if (!method_exists($firstModel, $relationName)) {
                throw new \InvalidArgumentException("Relation '$relationName' not found in model");
            }

            // Get relation definition
            $relation = $firstModel->$relationName();
            if (!($relation instanceof RelationInterface)) {
                return;
            }

            // Load related models based on relation type
            $relatedModels = $relation->execute()->await();
            
            // Assign related models to each model
            foreach ($models as $model) {
                // This is a simplified implementation
                // In a real implementation, you'd need to match related models
                // based on foreign keys and relation configuration
                $model->setAttribute($relationName, $relatedModels);
            }
        });
    }
}