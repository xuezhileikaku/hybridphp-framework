<?php

declare(strict_types=1);

use HybridPHP\Core\Database\Migration\AbstractMigration;
use HybridPHP\Core\Database\DatabaseInterface;
use Amp\Future;

/**
 * Migration: create_users_table
 */
class CreateUsersTable extends AbstractMigration
{
    protected string $description = 'Create users table';

    /**
     * Run the migration
     */
    public function up(DatabaseInterface $database): Future
    {
        return $this->createTable('users', [
            'id' => ['type' => 'INT', 'length' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
            'email' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
            'email_verified_at' => ['type' => 'TIMESTAMP', 'nullable' => true],
            'password' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
            'remember_token' => ['type' => 'VARCHAR', 'length' => 100, 'nullable' => true],
            'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
            'updated_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP', 'nullable' => true],
        ], [
            'primary_key' => 'id',
            'unique' => [
                'users_email_unique' => 'email'
            ],
            'indexes' => [
                'users_email_index' => 'email',
                'users_created_at_index' => 'created_at'
            ]
        ]);
    }

    /**
     * Reverse the migration
     */
    public function down(DatabaseInterface $database): Future
    {
        return $this->dropTable('users');
    }
}