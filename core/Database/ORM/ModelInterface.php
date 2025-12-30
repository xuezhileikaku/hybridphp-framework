<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\ORM;

use Amp\Future;

/**
 * Base model interface for ORM
 */
interface ModelInterface
{
    /**
     * Get the table name
     */
    public static function tableName(): string;

    /**
     * Get the primary key column name
     */
    public static function primaryKey(): string;

    /**
     * Get validation rules
     */
    public function rules(): array;

    /**
     * Get attribute labels
     */
    public function attributeLabels(): array;

    /**
     * Save the model (insert or update)
     */
    public function save(bool $validate = true): Future;

    /**
     * Delete the model
     */
    public function delete(): Future;

    /**
     * Validate the model
     */
    public function validate(): Future;

    /**
     * Check if model is new (not saved to database)
     */
    public function isNewRecord(): bool;

    /**
     * Get model attributes
     */
    public function getAttributes(): array;

    /**
     * Set model attributes
     */
    public function setAttributes(array $attributes): void;

    /**
     * Get a single attribute
     */
    public function getAttribute(string $name);

    /**
     * Set a single attribute
     */
    public function setAttribute(string $name, $value): void;

    /**
     * Check if attribute exists
     */
    public function hasAttribute(string $name): bool;

    /**
     * Get dirty attributes (changed since last save)
     */
    public function getDirtyAttributes(): array;

    /**
     * Check if model has been modified
     */
    public function isDirty(): bool;

    /**
     * Refresh model from database
     */
    public function refresh(): Future;
}