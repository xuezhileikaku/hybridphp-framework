<?php

declare(strict_types=1);

namespace App\Models;

use HybridPHP\Core\Database\ORM\ActiveRecord;
use HybridPHP\Core\Database\ORM\RelationInterface;
use HybridPHP\Core\Database\ORM\Relation;
use HybridPHP\Core\Auth\RBAC\PermissionInterface;

/**
 * Permission model
 */
class Permission extends ActiveRecord implements PermissionInterface
{
    /**
     * Get the table name
     */
    public static function tableName(): string
    {
        return 'permissions';
    }

    /**
     * Get validation rules
     */
    public function rules(): array
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', ['min' => 2, 'max' => 100]],
            [['name'], 'unique'],
            [['description'], 'string', ['max' => 255]],
            [['resource'], 'string', ['max' => 100]],
            [['action'], 'string', ['max' => 50]],
        ];
    }

    /**
     * Get attribute labels
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'name' => 'Permission Name',
            'description' => 'Description',
            'resource' => 'Resource',
            'action' => 'Action',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * Get permission roles (many-to-many relationship)
     */
    public function roles(): RelationInterface
    {
        return Relation::manyToManyRelation(
            $this,
            Role::class,
            'role_permissions',
            ['role_id' => 'id'],
            ['permission_id' => 'id']
        );
    }

    /**
     * Get permission users (many-to-many relationship)
     */
    public function users(): RelationInterface
    {
        return Relation::manyToManyRelation(
            $this,
            User::class,
            'user_permissions',
            ['user_id' => 'id'],
            ['permission_id' => 'id']
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
     * Find permission by name
     */
    public static function findByName(string $name)
    {
        return static::find()->where(['name' => $name])->one();
    }

    /**
     * Find permissions by resource
     */
    public static function findByResource(string $resource)
    {
        return static::find()->where(['resource' => $resource])->all();
    }

    // PermissionInterface implementation

    /**
     * Get permission ID
     */
    public function getId()
    {
        return $this->getAttribute('id');
    }

    /**
     * Get permission name
     */
    public function getName(): string
    {
        return $this->getAttribute('name') ?? '';
    }

    /**
     * Get permission description
     */
    public function getDescription(): string
    {
        return $this->getAttribute('description') ?? '';
    }

    /**
     * Get permission resource
     */
    public function getResource(): string
    {
        return $this->getAttribute('resource') ?? '';
    }

    /**
     * Get permission action
     */
    public function getAction(): string
    {
        return $this->getAttribute('action') ?? '';
    }

    /**
     * Check if permission matches resource and action
     */
    public function matches(string $resource, string $action): bool
    {
        return $this->getResource() === $resource && $this->getAction() === $action;
    }
}