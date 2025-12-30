<?php

use HybridPHP\Core\Database\Migration\Migration;

/**
 * Create authentication and authorization tables
 */
class CreateAuthTables extends Migration
{
    public function up(): void
    {
        // Users table (if not exists)
        if (!$this->hasTable('users')) {
            $this->createTable('users', [
                'id' => $this->primaryKey(),
                'username' => $this->string(50)->notNull()->unique(),
                'email' => $this->string(255)->notNull()->unique(),
                'password' => $this->string(255)->notNull(),
                'status' => $this->integer()->defaultValue(1),
                'remember_token' => $this->string(100)->null(),
                'created_at' => $this->timestamp()->null(),
                'updated_at' => $this->timestamp()->null(),
            ]);

            $this->createIndex('idx_users_username', 'users', 'username');
            $this->createIndex('idx_users_email', 'users', 'email');
            $this->createIndex('idx_users_status', 'users', 'status');
        }

        // Roles table
        $this->createTable('roles', [
            'id' => $this->primaryKey(),
            'name' => $this->string(50)->notNull()->unique(),
            'description' => $this->text()->null(),
            'created_at' => $this->timestamp()->null(),
            'updated_at' => $this->timestamp()->null(),
        ]);

        $this->createIndex('idx_roles_name', 'roles', 'name');

        // Permissions table
        $this->createTable('permissions', [
            'id' => $this->primaryKey(),
            'name' => $this->string(100)->notNull()->unique(),
            'description' => $this->text()->null(),
            'resource' => $this->string(100)->null(),
            'action' => $this->string(50)->null(),
            'created_at' => $this->timestamp()->null(),
            'updated_at' => $this->timestamp()->null(),
        ]);

        $this->createIndex('idx_permissions_name', 'permissions', 'name');
        $this->createIndex('idx_permissions_resource', 'permissions', 'resource');

        // User roles junction table
        $this->createTable('user_roles', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'role_id' => $this->integer()->notNull(),
            'created_at' => $this->timestamp()->null(),
        ]);

        $this->createIndex('idx_user_roles_user', 'user_roles', 'user_id');
        $this->createIndex('idx_user_roles_role', 'user_roles', 'role_id');
        $this->createIndex('idx_user_roles_unique', 'user_roles', ['user_id', 'role_id'], true);

        $this->addForeignKey('fk_user_roles_user', 'user_roles', 'user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_user_roles_role', 'user_roles', 'role_id', 'roles', 'id', 'CASCADE', 'CASCADE');

        // Role permissions junction table
        $this->createTable('role_permissions', [
            'id' => $this->primaryKey(),
            'role_id' => $this->integer()->notNull(),
            'permission_id' => $this->integer()->notNull(),
            'created_at' => $this->timestamp()->null(),
        ]);

        $this->createIndex('idx_role_permissions_role', 'role_permissions', 'role_id');
        $this->createIndex('idx_role_permissions_permission', 'role_permissions', 'permission_id');
        $this->createIndex('idx_role_permissions_unique', 'role_permissions', ['role_id', 'permission_id'], true);

        $this->addForeignKey('fk_role_permissions_role', 'role_permissions', 'role_id', 'roles', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_role_permissions_permission', 'role_permissions', 'permission_id', 'permissions', 'id', 'CASCADE', 'CASCADE');

        // User permissions (direct permissions) table
        $this->createTable('user_permissions', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'permission_id' => $this->integer()->notNull(),
            'created_at' => $this->timestamp()->null(),
        ]);

        $this->createIndex('idx_user_permissions_user', 'user_permissions', 'user_id');
        $this->createIndex('idx_user_permissions_permission', 'user_permissions', 'permission_id');
        $this->createIndex('idx_user_permissions_unique', 'user_permissions', ['user_id', 'permission_id'], true);

        $this->addForeignKey('fk_user_permissions_user', 'user_permissions', 'user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk_user_permissions_permission', 'user_permissions', 'permission_id', 'permissions', 'id', 'CASCADE', 'CASCADE');

        // MFA table
        $this->createTable('user_mfa', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'type' => $this->string(20)->notNull(), // totp, email, sms
            'secret' => $this->string(255)->null(),
            'enabled' => $this->boolean()->defaultValue(false),
            'created_at' => $this->timestamp()->null(),
            'updated_at' => $this->timestamp()->null(),
        ]);

        $this->createIndex('idx_user_mfa_user', 'user_mfa', 'user_id');
        $this->createIndex('idx_user_mfa_type', 'user_mfa', 'type');
        $this->createIndex('idx_user_mfa_enabled', 'user_mfa', 'enabled');

        $this->addForeignKey('fk_user_mfa_user', 'user_mfa', 'user_id', 'users', 'id', 'CASCADE', 'CASCADE');

        // Backup codes table
        $this->createTable('user_backup_codes', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'code' => $this->string(255)->notNull(), // hashed
            'used' => $this->boolean()->defaultValue(false),
            'used_at' => $this->timestamp()->null(),
            'created_at' => $this->timestamp()->null(),
        ]);

        $this->createIndex('idx_user_backup_codes_user', 'user_backup_codes', 'user_id');
        $this->createIndex('idx_user_backup_codes_code', 'user_backup_codes', 'code');
        $this->createIndex('idx_user_backup_codes_used', 'user_backup_codes', 'used');

        $this->addForeignKey('fk_user_backup_codes_user', 'user_backup_codes', 'user_id', 'users', 'id', 'CASCADE', 'CASCADE');

        // Login attempts table (for security)
        $this->createTable('login_attempts', [
            'id' => $this->primaryKey(),
            'ip_address' => $this->string(45)->notNull(),
            'email' => $this->string(255)->null(),
            'username' => $this->string(50)->null(),
            'user_agent' => $this->text()->null(),
            'successful' => $this->boolean()->defaultValue(false),
            'created_at' => $this->timestamp()->null(),
        ]);

        $this->createIndex('idx_login_attempts_ip', 'login_attempts', 'ip_address');
        $this->createIndex('idx_login_attempts_email', 'login_attempts', 'email');
        $this->createIndex('idx_login_attempts_username', 'login_attempts', 'username');
        $this->createIndex('idx_login_attempts_created', 'login_attempts', 'created_at');

        // Password reset tokens table
        $this->createTable('password_reset_tokens', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->notNull(),
            'token' => $this->string(255)->notNull(),
            'expires_at' => $this->timestamp()->notNull(),
            'used' => $this->boolean()->defaultValue(false),
            'created_at' => $this->timestamp()->null(),
        ]);

        $this->createIndex('idx_password_reset_user', 'password_reset_tokens', 'user_id');
        $this->createIndex('idx_password_reset_token', 'password_reset_tokens', 'token');
        $this->createIndex('idx_password_reset_expires', 'password_reset_tokens', 'expires_at');

        $this->addForeignKey('fk_password_reset_user', 'password_reset_tokens', 'user_id', 'users', 'id', 'CASCADE', 'CASCADE');
    }

    public function down(): void
    {
        $this->dropTable('password_reset_tokens');
        $this->dropTable('login_attempts');
        $this->dropTable('user_backup_codes');
        $this->dropTable('user_mfa');
        $this->dropTable('user_permissions');
        $this->dropTable('role_permissions');
        $this->dropTable('user_roles');
        $this->dropTable('permissions');
        $this->dropTable('roles');
        // Note: We don't drop users table as it might be used elsewhere
    }
}