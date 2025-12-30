<?php

declare(strict_types=1);

use HybridPHP\Core\Database\Migration\AbstractMigration;
use HybridPHP\Core\Database\DatabaseInterface;
use Amp\Future;

/**
 * Migration: Create products table
 */
class CreateProductsTable extends AbstractMigration
{
    protected string $description = 'Create products table';

    /**
     * Run the migration
     */
    public function up(DatabaseInterface $database): Future
    {
        return $this->createTable('products', [
            'id' => ['type' => 'INT', 'length' => 11, 'unsigned' => true, 'auto_increment' => true],
            // Add your columns here
            'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP', 'nullable' => true],
        ], [
            'primary_key' => 'id'
        ]);
    }

    /**
     * Reverse the migration
     */
    public function down(DatabaseInterface $database): Future
    {
        return $this->dropTable('products');
    }
}