<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\ORM;

use Amp\Future;

/**
 * Relation interface for model relationships
 */
interface RelationInterface
{
    /**
     * Define a has-one relationship
     */
    public function hasOne(string $class, array $link): self;

    /**
     * Define a has-many relationship
     */
    public function hasMany(string $class, array $link): self;

    /**
     * Define a belongs-to relationship
     */
    public function belongsTo(string $class, array $link): self;

    /**
     * Define a many-to-many relationship
     */
    public function manyToMany(string $class, string $via, array $link, array $viaLink): self;

    /**
     * Set the inverse relation
     */
    public function inverseOf(string $relationName): self;

    /**
     * Execute the relation query
     */
    public function execute(): Future;

    /**
     * Get relation type
     */
    public function getType(): string;

    /**
     * Get related model class
     */
    public function getModelClass(): string;

    /**
     * Get link configuration
     */
    public function getLink(): array;
}