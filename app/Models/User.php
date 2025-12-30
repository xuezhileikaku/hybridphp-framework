<?php

declare(strict_types=1);

namespace App\Models;

use HybridPHP\Core\Database\ORM\ActiveRecord;
use HybridPHP\Core\Database\ORM\RelationInterface;
use HybridPHP\Core\Database\ORM\Relation;
use HybridPHP\Core\Auth\UserInterface;

/**
 * User model example
 */
class User extends ActiveRecord implements UserInterface
{
    /**
     * Get the table name
     */
    public static function tableName(): string
    {
        return 'users';
    }

    /**
     * Get validation rules
     */
    public function rules(): array
    {
        return [
            [['username', 'email'], 'required'],
            [['username'], 'string', ['min' => 3, 'max' => 50]],
            [['email'], 'email'],
            [['username'], 'unique'],
            [['email'], 'unique'],
            [['password'], 'string', ['min' => 6]],
            [['status'], 'integer'],
        ];
    }

    /**
     * Get attribute labels
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'username' => 'Username',
            'email' => 'Email Address',
            'password' => 'Password',
            'status' => 'Status',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * Get user posts (has-many relationship)
     */
    public function posts(): RelationInterface
    {
        return Relation::hasManyRelation($this, Post::class, ['user_id' => 'id']);
    }

    /**
     * Get user profile (has-one relationship)
     */
    public function profile(): RelationInterface
    {
        return Relation::hasOneRelation($this, UserProfile::class, ['user_id' => 'id']);
    }

    /**
     * Get user roles (many-to-many relationship)
     */
    public function roles(): RelationInterface
    {
        return Relation::manyToManyRelation(
            $this,
            Role::class,
            'user_roles',
            ['role_id' => 'id'],
            ['user_id' => 'id']
        );
    }

    /**
     * Hash password before saving
     */
    protected function beforeSave(bool $insert): void
    {
        if ($this->getAttribute('password') && !$this->isPasswordHashed()) {
            $this->setAttribute('password', password_hash($this->getAttribute('password'), PASSWORD_DEFAULT));
        }

        if ($insert) {
            $this->setAttribute('created_at', date('Y-m-d H:i:s'));
        }
        $this->setAttribute('updated_at', date('Y-m-d H:i:s'));
    }

    /**
     * Check if password is already hashed
     */
    private function isPasswordHashed(): bool
    {
        $password = $this->getAttribute('password');
        return $password && strlen($password) === 60 && substr($password, 0, 4) === '$2y$';
    }

    /**
     * Verify password
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->getAttribute('password'));
    }

    /**
     * Get active users
     */
    public static function getActiveUsers()
    {
        return static::find()->where(['status' => 1]);
    }

    /**
     * Find user by username
     */
    public static function findByUsername(string $username)
    {
        return static::find()->where(['username' => $username])->one();
    }

    /**
     * Find user by email
     */
    public static function findByEmail(string $email)
    {
        return static::find()->where(['email' => $email])->one();
    }

    // UserInterface implementation methods

    /**
     * Get the user's unique identifier
     */
    public function getId()
    {
        return $this->getAttribute('id');
    }

    /**
     * Get the user's username
     */
    public function getUsername(): string
    {
        return $this->getAttribute('username') ?? '';
    }

    /**
     * Get the user's email
     */
    public function getEmail(): string
    {
        return $this->getAttribute('email') ?? '';
    }

    /**
     * Get the user's password hash
     */
    public function getPassword(): string
    {
        return $this->getAttribute('password') ?? '';
    }

    /**
     * Check if the user is active
     */
    public function isActive(): bool
    {
        return ($this->getAttribute('status') ?? 0) === 1;
    }

    /**
     * Get user roles
     */
    public function getRoles(): array
    {
        // This would typically be loaded from the relationship
        // For now, return empty array or implement lazy loading
        return $this->getAttribute('roles') ?? [];
    }

    /**
     * Get user permissions
     */
    public function getPermissions(): array
    {
        // This would typically be loaded from roles and direct permissions
        // For now, return empty array or implement lazy loading
        return $this->getAttribute('permissions') ?? [];
    }

    /**
     * Check if user has a specific role
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getRoles());
    }

    /**
     * Check if user has a specific permission
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->getPermissions());
    }

    /**
     * Get user attributes as array
     */
    public function toArray(): array
    {
        return $this->getAttributes();
    }
}