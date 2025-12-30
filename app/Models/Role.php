<?php

declare(strict_types=1);

namespace App\Models;

use HybridPHP\Core\Database\ORM\ActiveRecord;
use HybridPHP\Core\Database\ORM\RelationInterface;
use HybridPHP\Core\Database\ORM\Relation;
use HybridPHP\Core\Auth\RBAC\RoleInterface;

/**
 * Role model example
 */
class Role extends ActiveRecord implements RoleInterface
{
    /**
     * Get the table name
     */
    public static function tableName(): string
    {
        return 'roles';
    }

    /**
     * Get validation rules
     */
    public function rules(): array
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', ['min' => 2, 'max' => 50]],
            [['name'], 'unique'],
            [['description'], 'string', ['max' => 255]],
        ];
    }

    /**
     * Get attribute labels
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'name' => 'Role Name',
            'description' => 'Description',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * Get role users (many-to-many relationship)
     */
    public function users(): RelationInterface
    {
        return Relation::manyToManyRelation(
            $this,
            User::class,
            'user_roles',
            ['user_id' => 'id'],
            ['role_id' => 'id']
        );
    }

    /**
     * Set timestamps before saving
     */
    protected function beforeSave(bool $insert): void
    {
        if ($insert) {
            $this->setAttribute('created_at', date('Y-m-d H:i:s'));
        }
        $this->setAttribute('updated_at', date('Y-m-d H:i:s'));
    }

    /**
     * Find role by name
     */
    public static function findByName(string $name)
    {
        return static::find()->where(['name' => $name])->one();
    }

    // RoleInterface implementation

    /**
     * Get role ID
     */
    public function getId()
    {
        return $this->getAttribute('id');
    }

    /**
     * Get role name
     */
    public function getName(): string
    {
        return $this->getAttribute('name') ?? '';
    }

    /**
     * Get role description
     */
    public function getDescription(): string
    {
        return $this->getAttribute('description') ?? '';
    }

    /**
     * Get role permissions
     */
    public function getPermissions(): array
    {
        return $this->getAttribute('permissions') ?? [];
    }

    /**
     * Check if role has permission
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->getPermissions());
    }

    /**
     * Add permission to role
     */
    public function addPermission(string $permission): void
    {
        $permissions = $this->getPermissions();
        if (!in_array($permission, $permissions)) {
            $permissions[] = $permission;
            $this->setAttribute('permissions', $permissions);
        }
    }

    /**
     * Remove permission from role
     */
    public function removePermission(string $permission): void
    {
        $permissions = $this->getPermissions();
        $key = array_search($permission, $permissions);
        if ($key !== false) {
            unset($permissions[$key]);
            $this->setAttribute('permissions', array_values($permissions));
        }
    }
}