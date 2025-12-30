<?php

declare(strict_types=1);

use HybridPHP\Core\Database\Migration\AbstractMigration;
use HybridPHP\Core\Database\DatabaseInterface;
use Amp\Future;

/**
 * Migration: create_posts_table
 */
class CreatePostsTable extends AbstractMigration
{
    protected string $description = 'Create posts table';

    /**
     * Run the migration
     */
    public function up(DatabaseInterface $database): Future
    {
        return $this->createTable('posts', [
            'id' => ['type' => 'INT', 'length' => 11, 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'INT', 'length' => 11, 'unsigned' => true, 'nullable' => false],
            'title' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
            'slug' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
            'content' => ['type' => 'TEXT', 'nullable' => true],
            'excerpt' => ['type' => 'TEXT', 'nullable' => true],
            'status' => ['type' => 'ENUM', 'values' => ['draft', 'published', 'archived'], 'default' => 'draft'],
            'published_at' => ['type' => 'TIMESTAMP', 'nullable' => true],
            'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP', 'nullable' => true],
        ], [
            'primary_key' => 'id',
            'unique' => [
                'posts_slug_unique' => 'slug'
            ],
            'indexes' => [
                'posts_user_id_index' => 'user_id',
                'posts_status_index' => 'status',
                'posts_published_at_index' => 'published_at',
                'posts_created_at_index' => 'created_at'
            ]
        ]);
    }

    /**
     * Reverse the migration
     */
    public function down(DatabaseInterface $database): Future
    {
        return $this->dropTable('posts');
    }
}