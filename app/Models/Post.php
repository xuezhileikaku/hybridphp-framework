<?php

declare(strict_types=1);

namespace App\Models;

use HybridPHP\Core\Database\ORM\ActiveRecord;
use HybridPHP\Core\Database\ORM\RelationInterface;
use HybridPHP\Core\Database\ORM\Relation;

/**
 * Post model example
 */
class Post extends ActiveRecord
{
    /**
     * Get the table name
     */
    public static function tableName(): string
    {
        return 'posts';
    }

    /**
     * Get validation rules
     */
    public function rules(): array
    {
        return [
            [['title', 'content', 'user_id'], 'required'],
            [['title'], 'string', ['min' => 5, 'max' => 200]],
            [['content'], 'string', ['min' => 10]],
            [['user_id'], 'integer'],
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
            'title' => 'Title',
            'content' => 'Content',
            'user_id' => 'Author',
            'status' => 'Status',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * Get post author (belongs-to relationship)
     */
    public function author(): RelationInterface
    {
        return Relation::belongsToRelation($this, User::class, ['user_id' => 'id']);
    }

    /**
     * Get post comments (has-many relationship)
     */
    public function comments(): RelationInterface
    {
        return Relation::hasManyRelation($this, Comment::class, ['post_id' => 'id']);
    }

    /**
     * Get post tags (many-to-many relationship)
     */
    public function tags(): RelationInterface
    {
        return Relation::manyToManyRelation(
            $this,
            Tag::class,
            'post_tags',
            ['tag_id' => 'id'],
            ['post_id' => 'id']
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
     * Get published posts
     */
    public static function getPublished()
    {
        return static::find()->where(['status' => 1])->orderBy(['created_at' => 'DESC']);
    }

    /**
     * Get posts by user
     */
    public static function getByUser(int $userId)
    {
        return static::find()->where(['user_id' => $userId])->orderBy(['created_at' => 'DESC']);
    }

    /**
     * Search posts by title or content
     */
    public static function search(string $query)
    {
        return static::find()
            ->where(['or', 
                ['like', 'title', "%$query%"],
                ['like', 'content', "%$query%"]
            ])
            ->orderBy(['created_at' => 'DESC']);
    }
}