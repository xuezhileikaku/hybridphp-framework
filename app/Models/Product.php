<?php

declare(strict_types=1);

namespace App\Models;

use HybridPHP\Core\Database\Model\ActiveRecord;

/**
 * Product Model
 * 
 * @property int $id
 * @property string $created_at
 * @property string $updated_at
 */
class Product extends ActiveRecord
{
    /**
     * The table associated with the model
     */
    protected string $table = 'products';

    /**
     * The primary key for the model
     */
    protected string $primaryKey = 'id';

    /**
     * The attributes that are mass assignable
     */
    protected array $fillable = [
        // Add your fillable attributes here
    ];

    /**
     * The attributes that should be hidden for serialization
     */
    protected array $hidden = [
        // Add attributes to hide from JSON output
    ];

    /**
     * The attributes that should be cast to native types
     */
    protected array $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Indicates if the model should be timestamped
     */
    public bool $timestamps = true;
}