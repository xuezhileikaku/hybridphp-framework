<?php

declare(strict_types=1);

namespace App\Models;

use HybridPHP\Core\Database\ORM\ActiveRecord;
use HybridPHP\Core\Database\ORM\RelationInterface;
use HybridPHP\Core\Database\ORM\Relation;

/**
 * UserProfile model example
 */
class UserProfile extends ActiveRecord
{
    /**
     * Get the table name
     */
    public static function tableName(): string
    {
        return 'user_profiles';
    }

    /**
     * Get validation rules
     */
    public function rules(): array
    {
        return [
            [['user_id'], 'required'],
            [['user_id'], 'integer'],
            [['first_name', 'last_name'], 'string', ['max' => 100]],
            [['bio'], 'string', ['max' => 1000]],
            [['phone'], 'string', ['max' => 20]],
            [['website'], 'string', ['max' => 255]],
        ];
    }

    /**
     * Get attribute labels
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'user_id' => 'User ID',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'bio' => 'Biography',
            'phone' => 'Phone Number',
            'website' => 'Website',
            'avatar' => 'Avatar',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * Get profile owner (belongs-to relationship)
     */
    public function user(): RelationInterface
    {
        return Relation::belongsToRelation($this, User::class, ['user_id' => 'id']);
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
     * Get full name
     */
    public function getFullName(): string
    {
        $firstName = $this->getAttribute('first_name') ?? '';
        $lastName = $this->getAttribute('last_name') ?? '';
        return trim($firstName . ' ' . $lastName);
    }
}