<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\ORM;

use Amp\Future;
use HybridPHP\Core\Database\DatabaseInterface;
use HybridPHP\Core\Database\QueryBuilder;
use HybridPHP\Core\Container;
use function Amp\async;

/**
 * Base ActiveRecord implementation
 */
abstract class ActiveRecord implements ActiveRecordInterface
{
    protected array $attributes = [];
    protected array $oldAttributes = [];
    protected bool $isNewRecord = true;
    protected array $errors = [];
    protected static ?DatabaseInterface $db = null;

    /**
     * Constructor
     */
    public function __construct(array $attributes = [])
    {
        $this->setAttributes($attributes);
        $this->afterConstruct();
    }

    /**
     * Get database connection
     */
    protected static function getDb(): DatabaseInterface
    {
        if (self::$db === null) {
            self::$db = Container::getInstance()->get(DatabaseInterface::class);
        }
        return self::$db;
    }

    /**
     * Get the table name - must be implemented by child classes
     */
    public static function tableName(): string
    {
        throw new \RuntimeException('tableName() method must be implemented');
    }

    /**
     * Get the primary key column name
     */
    public static function primaryKey(): string
    {
        return 'id';
    }

    /**
     * Get validation rules
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Get attribute labels
     */
    public function attributeLabels(): array
    {
        return [];
    }

    /**
     * Find a model by primary key
     */
    public static function findByPk($pk): Future
    {
        return async(function () use ($pk) {
            $query = static::find()->where([static::primaryKey() => $pk]);
            return $query->one()->await();
        });
    }

    /**
     * Find all models matching criteria
     */
    public static function findAll(array $condition = []): Future
    {
        return async(function () use ($condition) {
            $query = static::find();
            if (!empty($condition)) {
                $query->where($condition);
            }
            return $query->all()->await();
        });
    }

    /**
     * Find one model matching criteria
     */
    public static function findOne(array $condition = []): Future
    {
        return async(function () use ($condition) {
            $query = static::find();
            if (!empty($condition)) {
                $query->where($condition);
            }
            return $query->one()->await();
        });
    }

    /**
     * Create a new query for this model
     */
    public static function find(): ActiveQueryInterface
    {
        return new ActiveQuery(static::class);
    }

    /**
     * Update all records matching condition
     */
    public static function updateAll(array $attributes, array $condition = []): Future
    {
        return async(function () use ($attributes, $condition) {
            $db = static::getDb();
            $query = new QueryBuilder($db);
            $query->table(static::tableName());
            
            if (!empty($condition)) {
                foreach ($condition as $column => $value) {
                    $query->where($column, '=', $value);
                }
            }
            
            return $query->update($attributes)->await();
        });
    }

    /**
     * Delete all records matching condition
     */
    public static function deleteAll(array $condition = []): Future
    {
        return async(function () use ($condition) {
            $db = static::getDb();
            $query = new QueryBuilder($db);
            $query->table(static::tableName());
            
            if (!empty($condition)) {
                foreach ($condition as $column => $value) {
                    $query->where($column, '=', $value);
                }
            }
            
            return $query->delete()->await();
        });
    }

    /**
     * Count records matching condition
     */
    public static function count(array $condition = []): Future
    {
        return async(function () use ($condition) {
            $query = static::find();
            if (!empty($condition)) {
                $query->where($condition);
            }
            return $query->count()->await();
        });
    }

    /**
     * Check if record exists
     */
    public static function exists(array $condition = []): Future
    {
        return async(function () use ($condition) {
            $query = static::find();
            if (!empty($condition)) {
                $query->where($condition);
            }
            return $query->exists()->await();
        });
    }

    /**
     * Save the model (insert or update)
     */
    public function save(bool $validate = true): Future
    {
        return async(function () use ($validate) {
            if ($validate && !$this->validate()->await()) {
                return false;
            }

            $this->beforeSave($this->isNewRecord);

            if ($this->isNewRecord) {
                $result = $this->insert()->await();
            } else {
                $result = $this->update()->await();
            }

            if ($result) {
                $this->afterSave($this->isNewRecord);
                $this->oldAttributes = $this->attributes;
                $this->isNewRecord = false;
            }

            return $result;
        });
    }

    /**
     * Delete the model
     */
    public function delete(): Future
    {
        return async(function () {
            if ($this->isNewRecord) {
                return false;
            }

            $this->beforeDelete();

            $db = static::getDb();
            $query = new QueryBuilder($db);
            $result = $query->table(static::tableName())
                ->where(static::primaryKey(), '=', $this->getPrimaryKey())
                ->delete()->await();

            if ($result) {
                $this->afterDelete();
            }

            return $result;
        });
    }

    /**
     * Validate the model
     */
    public function validate(): Future
    {
        return async(function () {
            $this->errors = [];
            $this->beforeValidate();

            $rules = $this->rules();
            foreach ($rules as $rule) {
                $this->validateRule($rule)->await();
            }

            $this->afterValidate();
            return empty($this->errors);
        });
    }

    /**
     * Check if model is new (not saved to database)
     */
    public function isNewRecord(): bool
    {
        return $this->isNewRecord;
    }

    /**
     * Get model attributes
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Set model attributes
     */
    public function setAttributes(array $attributes): void
    {
        foreach ($attributes as $name => $value) {
            $this->setAttribute($name, $value);
        }
    }

    /**
     * Get a single attribute
     */
    public function getAttribute(string $name)
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Set a single attribute
     */
    public function setAttribute(string $name, $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * Check if attribute exists
     */
    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    /**
     * Get dirty attributes (changed since last save)
     */
    public function getDirtyAttributes(): array
    {
        $dirty = [];
        foreach ($this->attributes as $name => $value) {
            if (!isset($this->oldAttributes[$name]) || $this->oldAttributes[$name] !== $value) {
                $dirty[$name] = $value;
            }
        }
        return $dirty;
    }

    /**
     * Check if model has been modified
     */
    public function isDirty(): bool
    {
        return !empty($this->getDirtyAttributes());
    }

    /**
     * Refresh model from database
     */
    public function refresh(): Future
    {
        return async(function () {
            if ($this->isNewRecord) {
                return false;
            }

            $model = static::findByPk($this->getPrimaryKey())->await();
            if ($model) {
                $this->attributes = $model->getAttributes();
                $this->oldAttributes = $this->attributes;
                return true;
            }

            return false;
        });
    }

    /**
     * Get primary key value
     */
    public function getPrimaryKey()
    {
        return $this->getAttribute(static::primaryKey());
    }

    /**
     * Set primary key value
     */
    public function setPrimaryKey($value): void
    {
        $this->setAttribute(static::primaryKey(), $value);
    }

    /**
     * Magic getter for attributes
     */
    public function __get(string $name)
    {
        if ($this->hasAttribute($name)) {
            return $this->getAttribute($name);
        }

        // Check for relations
        if (method_exists($this, $name)) {
            return $this->$name();
        }

        throw new \InvalidArgumentException("Property '$name' not found");
    }

    /**
     * Magic setter for attributes
     */
    public function __set(string $name, $value): void
    {
        $this->setAttribute($name, $value);
    }

    /**
     * Magic isset for attributes
     */
    public function __isset(string $name): bool
    {
        return $this->hasAttribute($name);
    }

    /**
     * Insert new record
     */
    protected function insert(): Future
    {
        return async(function () {
            $db = static::getDb();
            $query = new QueryBuilder($db);
            
            $attributes = $this->getDirtyAttributes();
            if (empty($attributes)) {
                return false;
            }

            $result = $query->table(static::tableName())->insert($attributes)->await();
            
            // Set primary key if auto-increment
            if ($result && !$this->getPrimaryKey()) {
                $this->setPrimaryKey($result->getLastInsertId());
            }

            return $result !== false;
        });
    }

    /**
     * Update existing record
     */
    protected function update(): Future
    {
        return async(function () {
            $attributes = $this->getDirtyAttributes();
            if (empty($attributes)) {
                return true; // No changes to save
            }

            $db = static::getDb();
            $query = new QueryBuilder($db);
            
            $result = $query->table(static::tableName())
                ->where(static::primaryKey(), '=', $this->getPrimaryKey())
                ->update($attributes)->await();

            return $result !== false;
        });
    }

    /**
     * Validate a single rule
     */
    protected function validateRule(array $rule): Future
    {
        return async(function () use ($rule) {
            $attributes = $rule[0] ?? [];
            $validator = $rule[1] ?? '';
            $params = $rule[2] ?? [];

            if (!is_array($attributes)) {
                $attributes = [$attributes];
            }

            foreach ($attributes as $attribute) {
                $this->validateAttribute($attribute, $validator, $params)->await();
            }
        });
    }

    /**
     * Validate a single attribute
     */
    protected function validateAttribute(string $attribute, string $validator, array $params): Future
    {
        return async(function () use ($attribute, $validator, $params) {
            $value = $this->getAttribute($attribute);

            switch ($validator) {
                case 'required':
                    if (empty($value)) {
                        $this->addError($attribute, "$attribute is required");
                    }
                    break;

                case 'string':
                    if (!is_string($value)) {
                        $this->addError($attribute, "$attribute must be a string");
                    }
                    if (isset($params['max']) && strlen($value) > $params['max']) {
                        $this->addError($attribute, "$attribute is too long");
                    }
                    if (isset($params['min']) && strlen($value) < $params['min']) {
                        $this->addError($attribute, "$attribute is too short");
                    }
                    break;

                case 'integer':
                    if (!is_int($value) && !ctype_digit((string)$value)) {
                        $this->addError($attribute, "$attribute must be an integer");
                    }
                    break;

                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $this->addError($attribute, "$attribute must be a valid email");
                    }
                    break;

                case 'unique':
                    $count = static::count([$attribute => $value])->await();
                    if ($count > 0) {
                        $this->addError($attribute, "$attribute must be unique");
                    }
                    break;
            }
        });
    }

    /**
     * Add validation error
     */
    protected function addError(string $attribute, string $message): void
    {
        if (!isset($this->errors[$attribute])) {
            $this->errors[$attribute] = [];
        }
        $this->errors[$attribute][] = $message;
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check if model has errors
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    // Event methods - can be overridden by child classes
    protected function afterConstruct(): void {}
    protected function beforeSave(bool $insert): void {}
    protected function afterSave(bool $insert): void {}
    protected function beforeDelete(): void {}
    protected function afterDelete(): void {}
    protected function beforeValidate(): void {}
    protected function afterValidate(): void {}
}