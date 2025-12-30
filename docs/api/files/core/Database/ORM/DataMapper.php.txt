<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\ORM;

use Amp\Future;
use HybridPHP\Core\Database\DatabaseInterface;
use HybridPHP\Core\Database\QueryBuilder;
use HybridPHP\Core\Container;
use function Amp\async;

/**
 * DataMapper implementation for ORM
 */
abstract class DataMapper implements DataMapperInterface
{
    protected DatabaseInterface $db;
    protected string $tableName;
    protected string $entityClass;
    protected string $primaryKey = 'id';
    protected array $fieldMapping = [];

    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Container::getInstance()->get(DatabaseInterface::class);
        $this->initialize();
    }

    /**
     * Initialize mapper - must be implemented by child classes
     */
    abstract protected function initialize(): void;

    /**
     * Set table name
     */
    protected function setTableName(string $tableName): void
    {
        $this->tableName = $tableName;
    }

    /**
     * Set entity class
     */
    protected function setEntityClass(string $entityClass): void
    {
        $this->entityClass = $entityClass;
    }

    /**
     * Set primary key column
     */
    protected function setPrimaryKey(string $primaryKey): void
    {
        $this->primaryKey = $primaryKey;
    }

    /**
     * Set field mapping between entity properties and database columns
     */
    protected function setFieldMapping(array $mapping): void
    {
        $this->fieldMapping = $mapping;
    }

    /**
     * Find entity by ID
     */
    public function findById($id): Future
    {
        return async(function () use ($id) {
            $query = new QueryBuilder($this->db);
            $result = $query->table($this->tableName)
                ->where($this->primaryKey, '=', $id)
                ->first()->await();

            return $result ? $this->mapToEntity($result) : null;
        });
    }

    /**
     * Find entities by criteria
     */
    public function findBy(array $criteria, array $orderBy = [], int $limit = null, int $offset = null): Future
    {
        return async(function () use ($criteria, $orderBy, $limit, $offset) {
            $query = new QueryBuilder($this->db);
            $query->table($this->tableName);

            // Apply criteria
            foreach ($criteria as $column => $value) {
                if (is_array($value)) {
                    $query->whereIn($column, $value);
                } else {
                    $query->where($column, '=', $value);
                }
            }

            // Apply order by
            foreach ($orderBy as $column => $direction) {
                $query->orderBy($column, $direction);
            }

            // Apply limit and offset
            if ($limit !== null) {
                $query->limit($limit);
            }
            if ($offset !== null) {
                $query->offset($offset);
            }

            $results = $query->get()->await();
            
            return array_map([$this, 'mapToEntity'], $results);
        });
    }

    /**
     * Find one entity by criteria
     */
    public function findOneBy(array $criteria): Future
    {
        return async(function () use ($criteria) {
            $results = $this->findBy($criteria, [], 1)->await();
            return empty($results) ? null : $results[0];
        });
    }

    /**
     * Find all entities
     */
    public function findAll(array $orderBy = [], int $limit = null, int $offset = null): Future
    {
        return $this->findBy([], $orderBy, $limit, $offset);
    }

    /**
     * Save entity (insert or update)
     */
    public function save(object $entity): Future
    {
        return async(function () use ($entity) {
            $this->validateEntity($entity);
            
            $data = $this->mapFromEntity($entity);
            $primaryKeyValue = $this->getEntityId($entity);

            if ($primaryKeyValue === null) {
                // Insert new entity
                $result = $this->insert($data)->await();
                if ($result) {
                    $this->setEntityId($entity, $result->getLastInsertId());
                }
                return $result !== false;
            } else {
                // Update existing entity
                return $this->update($primaryKeyValue, $data)->await();
            }
        });
    }

    /**
     * Delete entity
     */
    public function delete(object $entity): Future
    {
        return async(function () use ($entity) {
            $primaryKeyValue = $this->getEntityId($entity);
            if ($primaryKeyValue === null) {
                return false;
            }

            $query = new QueryBuilder($this->db);
            $result = $query->table($this->tableName)
                ->where($this->primaryKey, '=', $primaryKeyValue)
                ->delete()->await();

            return $result !== false;
        });
    }

    /**
     * Delete entities by criteria
     */
    public function deleteBy(array $criteria): Future
    {
        return async(function () use ($criteria) {
            $query = new QueryBuilder($this->db);
            $query->table($this->tableName);

            foreach ($criteria as $column => $value) {
                if (is_array($value)) {
                    $query->whereIn($column, $value);
                } else {
                    $query->where($column, '=', $value);
                }
            }

            $result = $query->delete()->await();
            return $result !== false;
        });
    }

    /**
     * Count entities by criteria
     */
    public function count(array $criteria = []): Future
    {
        return async(function () use ($criteria) {
            $query = new QueryBuilder($this->db);
            $query->table($this->tableName);

            foreach ($criteria as $column => $value) {
                if (is_array($value)) {
                    $query->whereIn($column, $value);
                } else {
                    $query->where($column, '=', $value);
                }
            }

            return $query->count()->await();
        });
    }

    /**
     * Check if entity exists
     */
    public function exists(array $criteria): Future
    {
        return async(function () use ($criteria) {
            $count = $this->count($criteria)->await();
            return $count > 0;
        });
    }

    /**
     * Begin transaction
     */
    public function beginTransaction(): Future
    {
        return $this->db->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit(): Future
    {
        return $this->db->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback(): Future
    {
        return $this->db->rollback();
    }

    /**
     * Execute in transaction
     */
    public function transaction(callable $callback): Future
    {
        return $this->db->transaction($callback);
    }

    /**
     * Insert new record
     */
    protected function insert(array $data): Future
    {
        return async(function () use ($data) {
            $query = new QueryBuilder($this->db);
            return $query->table($this->tableName)->insert($data)->await();
        });
    }

    /**
     * Update existing record
     */
    protected function update($id, array $data): Future
    {
        return async(function () use ($id, $data) {
            $query = new QueryBuilder($this->db);
            $result = $query->table($this->tableName)
                ->where($this->primaryKey, '=', $id)
                ->update($data)->await();
            
            return $result !== false;
        });
    }

    /**
     * Map database row to entity
     */
    protected function mapToEntity(array $row): object
    {
        $entity = new $this->entityClass();
        
        foreach ($row as $column => $value) {
            $property = $this->getPropertyName($column);
            if (property_exists($entity, $property)) {
                $entity->$property = $this->convertFromDatabase($property, $value);
            }
        }

        return $entity;
    }

    /**
     * Map entity to database row
     */
    protected function mapFromEntity(object $entity): array
    {
        $data = [];
        $reflection = new \ReflectionClass($entity);
        
        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $propertyName = $property->getName();
            $columnName = $this->getColumnName($propertyName);
            
            if ($columnName !== $this->primaryKey || $property->getValue($entity) !== null) {
                $data[$columnName] = $this->convertToDatabase($propertyName, $property->getValue($entity));
            }
        }

        return $data;
    }

    /**
     * Get property name from column name
     */
    protected function getPropertyName(string $column): string
    {
        if (isset($this->fieldMapping[$column])) {
            return $this->fieldMapping[$column];
        }
        
        // Convert snake_case to camelCase
        return lcfirst(str_replace('_', '', ucwords($column, '_')));
    }

    /**
     * Get column name from property name
     */
    protected function getColumnName(string $property): string
    {
        $flipped = array_flip($this->fieldMapping);
        if (isset($flipped[$property])) {
            return $flipped[$property];
        }
        
        // Convert camelCase to snake_case
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $property));
    }

    /**
     * Get entity ID
     */
    protected function getEntityId(object $entity)
    {
        $property = $this->getPropertyName($this->primaryKey);
        return property_exists($entity, $property) ? $entity->$property : null;
    }

    /**
     * Set entity ID
     */
    protected function setEntityId(object $entity, $id): void
    {
        $property = $this->getPropertyName($this->primaryKey);
        if (property_exists($entity, $property)) {
            $entity->$property = $id;
        }
    }

    /**
     * Convert value from database format
     */
    protected function convertFromDatabase(string $property, $value)
    {
        // Override in child classes for custom conversions
        return $value;
    }

    /**
     * Convert value to database format
     */
    protected function convertToDatabase(string $property, $value)
    {
        // Override in child classes for custom conversions
        return $value;
    }

    /**
     * Validate entity before save
     */
    protected function validateEntity(object $entity): void
    {
        if (!($entity instanceof $this->entityClass)) {
            throw new \InvalidArgumentException("Entity must be instance of {$this->entityClass}");
        }
    }
}