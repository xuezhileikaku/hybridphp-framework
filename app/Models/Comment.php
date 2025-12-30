<?php

declare(strict_types=1);

namespace App\Models;

use HybridPHP\Core\Database\ORM\ActiveRecord;
use HybridPHP\Core\Database\ORM\RelationInterface;
use HybridPHP\Core\Database\ORM\Relation;

/**
 * Comment model example
 */
class Comment extends ActiveRecord
{
    /**
     * Get the table name
     */
    public static function tableName(): string
    {
        return 'comments';
    }

    /**
     * Get validation rules
     */
    public function rules(): array
    {
        return [
            [['content', 'post_id', 'user_id'], 'required'],
            [['content'], 'string', ['min' => 5, 'max' => 1000]],
            [['post_id', 'user_id'], 'integer'],
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
            'content' => 'Content',
            'post_id' => 'Post',
            'user_id' => 'Author',
            'status' => 'Status',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * Get comment post (belongs-to relationship)
     */
    public function post(): RelationInterface
    {
        return Relation::belongsToRelation($this, Post::class, ['post_id' => 'id']);
    }

    /**
     * Get comment author (belongs-to relationship)
     */
    public function author(): RelationInterface
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
     * Get approved comments
     */
    public static function getApproved()
    {
        return static::find()->where(['status' => 1])->orderBy(['created_at' => 'DESC']);
    }

    /**
     * Get comments by post
     */
    public static function getByPost(int $postId)
    {
        return static::find()->where(['post_id' => $postId])->orderBy(['created_at' => 'ASC']);
    }
}