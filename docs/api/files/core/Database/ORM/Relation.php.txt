<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\ORM;

use Amp\Future;
use function Amp\async;

/**
 * Relation implementation for model relationships
 */
class Relation implements RelationInterface
{
    const HAS_ONE = 'hasOne';
    const HAS_MANY = 'hasMany';
    const BELONGS_TO = 'belongsTo';
    const MANY_TO_MANY = 'manyToMany';

    protected string $type;
    protected string $modelClass;
    protected array $link;
    protected ?string $via = null;
    protected array $viaLink = [];
    protected ?string $inverseOf = null;
    protected ActiveRecordInterface $primaryModel;
    protected ?ActiveQueryInterface $query = null;

    public function __construct(string $type, string $modelClass, array $link, ActiveRecordInterface $primaryModel)
    {
        $this->type = $type;
        $this->modelClass = $modelClass;
        $this->link = $link;
        $this->primaryModel = $primaryModel;
    }

    /**
     * Define a has-one relationship
     */
    public function hasOne(string $class, array $link): self
    {
        return new self(self::HAS_ONE, $class, $link, $this->primaryModel);
    }

    /**
     * Define a has-many relationship
     */
    public function hasMany(string $class, array $link): self
    {
        return new self(self::HAS_MANY, $class, $link, $this->primaryModel);
    }

    /**
     * Define a belongs-to relationship
     */
    public function belongsTo(string $class, array $link): self
    {
        return new self(self::BELONGS_TO, $class, $link, $this->primaryModel);
    }

    /**
     * Define a many-to-many relationship
     */
    public function manyToMany(string $class, string $via, array $link, array $viaLink): self
    {
        $relation = new self(self::MANY_TO_MANY, $class, $link, $this->primaryModel);
        $relation->via = $via;
        $relation->viaLink = $viaLink;
        return $relation;
    }

    /**
     * Set the inverse relation
     */
    public function inverseOf(string $relationName): self
    {
        $this->inverseOf = $relationName;
        return $this;
    }

    /**
     * Execute the relation query
     */
    public function execute(): Future
    {
        return async(function () {
            switch ($this->type) {
                case self::HAS_ONE:
                    return $this->executeHasOne()->await();
                case self::HAS_MANY:
                    return $this->executeHasMany()->await();
                case self::BELONGS_TO:
                    return $this->executeBelongsTo()->await();
                case self::MANY_TO_MANY:
                    return $this->executeManyToMany()->await();
                default:
                    throw new \InvalidArgumentException("Unknown relation type: {$this->type}");
            }
        });
    }

    /**
     * Get relation type
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get related model class
     */
    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    /**
     * Get link configuration
     */
    public function getLink(): array
    {
        return $this->link;
    }

    /**
     * Execute has-one relation
     */
    protected function executeHasOne(): Future
    {
        return async(function () {
            $query = $this->createQuery();
            return $query->one()->await();
        });
    }

    /**
     * Execute has-many relation
     */
    protected function executeHasMany(): Future
    {
        return async(function () {
            $query = $this->createQuery();
            return $query->all()->await();
        });
    }

    /**
     * Execute belongs-to relation
     */
    protected function executeBelongsTo(): Future
    {
        return async(function () {
            $query = $this->createQuery();
            return $query->one()->await();
        });
    }

    /**
     * Execute many-to-many relation
     */
    protected function executeManyToMany(): Future
    {
        return async(function () {
            if (!$this->via) {
                throw new \InvalidArgumentException("Many-to-many relation requires 'via' table");
            }

            $modelClass = $this->modelClass;
            $query = $modelClass::find();

            $viaTable = $this->via;
            $targetTable = $modelClass::tableName();

            $query->innerJoin($viaTable, $this->buildJoinCondition($viaTable, $targetTable));

            $condition = [];
            foreach ($this->viaLink as $viaColumn => $primaryColumn) {
                $condition[$viaTable . '.' . $viaColumn] = $this->primaryModel->getAttribute($primaryColumn);
            }
            $query->where($condition);

            return $query->all()->await();
        });
    }

    /**
     * Create query for relation
     */
    protected function createQuery(): ActiveQueryInterface
    {
        $modelClass = $this->modelClass;
        $query = $modelClass::find();
        
        // Build where condition based on link
        $condition = [];
        foreach ($this->link as $foreignKey => $primaryKey) {
            $condition[$foreignKey] = $this->primaryModel->getAttribute($primaryKey);
        }
        
        $query->where($condition);
        
        return $query;
    }

    /**
     * Build join condition for many-to-many
     */
    protected function buildJoinCondition(string $viaTable, string $targetTable): string
    {
        $conditions = [];
        foreach ($this->link as $targetColumn => $viaColumn) {
            $conditions[] = "$targetTable.$targetColumn = $viaTable.$viaColumn";
        }
        return implode(' AND ', $conditions);
    }

    /**
     * Create relation instance for model
     */
    public static function hasOneRelation(ActiveRecordInterface $model, string $class, array $link): self
    {
        return new self(self::HAS_ONE, $class, $link, $model);
    }

    /**
     * Create has-many relation instance for model
     */
    public static function hasManyRelation(ActiveRecordInterface $model, string $class, array $link): self
    {
        return new self(self::HAS_MANY, $class, $link, $model);
    }

    /**
     * Create belongs-to relation instance for model
     */
    public static function belongsToRelation(ActiveRecordInterface $model, string $class, array $link): self
    {
        return new self(self::BELONGS_TO, $class, $link, $model);
    }

    /**
     * Create many-to-many relation instance for model
     */
    public static function manyToManyRelation(ActiveRecordInterface $model, string $class, string $via, array $link, array $viaLink): self
    {
        $relation = new self(self::MANY_TO_MANY, $class, $link, $model);
        $relation->via = $via;
        $relation->viaLink = $viaLink;
        return $relation;
    }
}