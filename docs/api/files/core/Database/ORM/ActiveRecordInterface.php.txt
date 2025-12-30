<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\ORM;

use Amp\Future;

/**
 * ActiveRecord interface for ORM
 */
interface ActiveRecordInterface extends ModelInterface
{
    /**
     * Find a model by primary key
     */
    public static function findByPk($pk): Future;

    /**
     * Find all models matching criteria
     */
    public static function findAll(array $condition = []): Future;

    /**
     * Find one model matching criteria
     */
    public static function findOne(array $condition = []): Future;

    /**
     * Create a new query for this model
     */
    public static function find(): ActiveQueryInterface;

    /**
     * Update all records matching condition
     */
    public static function updateAll(array $attributes, array $condition = []): Future;

    /**
     * Delete all records matching condition
     */
    public static function deleteAll(array $condition = []): Future;

    /**
     * Count records matching condition
     */
    public static function count(array $condition = []): Future;

    /**
     * Check if record exists
     */
    public static function exists(array $condition = []): Future;
}