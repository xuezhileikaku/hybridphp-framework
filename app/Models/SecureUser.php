<?php

declare(strict_types=1);

namespace HybridPHP\App\Models;

use HybridPHP\Core\Database\ORM\ActiveRecord;
use HybridPHP\Core\Security\EncryptedModelTrait;

/**
 * Example secure user model with encrypted fields
 */
class SecureUser extends ActiveRecord
{
    use EncryptedModelTrait;

    /**
     * Table name
     */
    public static function tableName(): string
    {
        return 'secure_users';
    }

    /**
     * Primary key
     */
    public static function primaryKey(): string
    {
        return 'id';
    }

    /**
     * Define encrypted fields
     */
    protected function encryptedFields(): array
    {
        return [
            'email',
            'phone',
            'ssn',
            'credit_card',
            'personal_notes'
        ];
    }

    /**
     * Define masked fields for logging
     */
    protected function maskedFields(): array
    {
        return [
            'email' => 'email',
            'phone' => 'phone',
            'ssn' => 'ssn',
            'credit_card' => 'credit_card',
            'first_name' => 'name',
            'last_name' => 'name',
            'address' => 'address'
        ];
    }

    /**
     * Validation rules
     */
    public function rules(): array
    {
        return [
            [['username', 'email'], 'required'],
            [['email'], 'email'],
            [['username'], 'string', 'min' => 3, 'max' => 50],
            [['first_name', 'last_name'], 'string', 'max' => 100],
            [['phone'], 'string', 'max' => 20],
            [['ssn'], 'string', 'length' => 11], // Format: XXX-XX-XXXX
            [['credit_card'], 'string', 'max' => 19], // Format: XXXX-XXXX-XXXX-XXXX
            [['personal_notes'], 'string'],
            [['is_active'], 'boolean'],
        ];
    }

    /**
     * Attribute labels
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'username' => 'Username',
            'email' => 'Email Address',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'phone' => 'Phone Number',
            'ssn' => 'Social Security Number',
            'credit_card' => 'Credit Card Number',
            'personal_notes' => 'Personal Notes',
            'is_active' => 'Active Status',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * Get full name
     */
    public function getFullName(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }

    /**
     * Get masked email for display
     */
    public function getMaskedEmail(): string
    {
        if (empty($this->email)) {
            return '';
        }
        
        return $this->getDataMasking()->maskData($this->email, 'email');
    }

    /**
     * Get masked phone for display
     */
    public function getMaskedPhone(): string
    {
        if (empty($this->phone)) {
            return '';
        }
        
        return $this->getDataMasking()->maskData($this->phone, 'phone');
    }

    /**
     * Search by username (non-encrypted field)
     */
    public static function findByUsername(string $username): \Amp\Future
    {
        return static::findOne(['username' => $username]);
    }

    /**
     * Search by email hash (for encrypted email field)
     */
    public static function findByEmailHash(string $email): \Amp\Future
    {
        $emailHash = hash('sha256', $email);
        return static::findOne(['email_hash' => $emailHash]);
    }

    /**
     * Before save - generate search hashes for encrypted fields
     */
    protected function beforeSave(): \Amp\Future
    {
        return \Amp\async(function () {
            // Generate search hashes for encrypted fields
            if (!empty($this->email)) {
                $this->email_hash = hash('sha256', $this->email);
            }
            
            if (!empty($this->phone)) {
                $this->phone_hash = hash('sha256', $this->phone);
            }
            
            // Call parent
            parent::beforeSave()->await();
        });
    }

    /**
     * Get safe attributes for API responses
     */
    public function getSafeAttributes(): array
    {
        $safe = [
            'id' => $this->id,
            'username' => $this->username,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->getFullName(),
            'email' => $this->getMaskedEmail(),
            'phone' => $this->getMaskedPhone(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
        
        return $safe;
    }

    /**
     * Create table migration
     */
    public static function createTable(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS secure_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                email TEXT, -- Encrypted
                email_hash VARCHAR(64), -- For searching
                first_name VARCHAR(100),
                last_name VARCHAR(100),
                phone TEXT, -- Encrypted
                phone_hash VARCHAR(64), -- For searching
                ssn TEXT, -- Encrypted
                credit_card TEXT, -- Encrypted
                personal_notes TEXT, -- Encrypted
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_username (username),
                INDEX idx_email_hash (email_hash),
                INDEX idx_phone_hash (phone_hash),
                INDEX idx_active (is_active),
                INDEX idx_created (created_at)
            )
        ";
    }
}