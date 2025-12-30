<?php

declare(strict_types=1);

namespace App\Models;

use HybridPHP\Core\Database\ORM\ActiveRecord;
use HybridPHP\Core\Database\ORM\RelationInterface;
use HybridPHP\Core\Database\ORM\Relation;

/**
 * Tag model example
 */
class Tag extends ActiveRecord
{
    /**
     * Get the table name
     */
    public static function tableName(): string
    {
        return 'tags';
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
            'name' => 'Tag Name',
            'description' => 'Description',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * Get tag posts (many-to-many relationship)
     */
    public function posts(): RelationInterface
    {
        return Relation::manyToManyRelation(
            $this,
            Post::class,
            'post_tags',
            ['post_id' => 'id'],
            ['tag_id' => 'id']
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
     * Find tag by name
     */
    public static function findByName(string $name)
    {
        return static::find()->where(['name' => $name])->one();
    }

    /**
     * Get popular tags
     */
    public static function getPopular(int $limit = 10)
    {
        return static::find()
            ->innerJoin('post_tags', 'post_tags.tag_id = tags.id')
            ->groupBy(['tags.id'])
            ->orderBy(['COUNT(post_tags.post_id)' => 'DESC'])
            ->limit($limit);
    }
}