<?php

declare(strict_types=1);

namespace HybridPHP\Core\Security;

use Amp\Future;
use HybridPHP\Core\Container;
use function Amp\async;

/**
 * Trait for models that need encrypted fields
 */
trait EncryptedModelTrait
{
    protected array $encryptedFields = [];
    protected array $maskedFields = [];
    private ?EncryptionService $encryptionService = null;
    private ?AuditLogger $auditLogger = null;
    private ?DataMasking $dataMasking = null;

    /**
     * Get encryption service
     */
    protected function getEncryptionService(): EncryptionService
    {
        if ($this->encryptionService === null) {
            $this->encryptionService = Container::getInstance()->get(EncryptionService::class);
        }
        return $this->encryptionService;
    }

    /**
     * Get audit logger
     */
    protected function getAuditLogger(): AuditLogger
    {
        if ($this->auditLogger === null) {
            $this->auditLogger = Container::getInstance()->get(AuditLogger::class);
        }
        return $this->auditLogger;
    }

    /**
     * Get data masking service
     */
    protected function getDataMasking(): DataMasking
    {
        if ($this->dataMasking === null) {
            $this->dataMasking = Container::getInstance()->get(DataMasking::class);
        }
        return $this->dataMasking;
    }

    /**
     * Define which fields should be encrypted
     */
    protected function encryptedFields(): array
    {
        return $this->encryptedFields;
    }

    /**
     * Define which fields should be masked in logs
     */
    protected function maskedFields(): array
    {
        return $this->maskedFields;
    }

    /**
     * Encrypt sensitive fields before saving
     */
    protected function encryptAttributes(): Future
    {
        return async(function () {
            $encryptedFields = $this->encryptedFields();

            if (empty($encryptedFields)) {
                return;
            }

            foreach ($encryptedFields as $field) {
                if (isset($this->attributes[$field]) && !empty($this->attributes[$field])) {
                    // Only encrypt if not already encrypted
                    if (!$this->isEncrypted($this->attributes[$field])) {
                        $this->attributes[$field] = $this->getEncryptionService()
                            ->encrypt($this->attributes[$field])->await();

                        // Log encryption event
                        $this->logEncryptionEvent('encrypt', $field)->await();
                    }
                }
            }
        });
    }

    /**
     * Decrypt sensitive fields after loading
     */
    protected function decryptAttributes(): Future
    {
        return async(function () {
            $encryptedFields = $this->encryptedFields();

            if (empty($encryptedFields)) {
                return;
            }

            foreach ($encryptedFields as $field) {
                if (isset($this->attributes[$field]) && !empty($this->attributes[$field])) {
                    // Only decrypt if encrypted
                    if ($this->isEncrypted($this->attributes[$field])) {
                        try {
                            $this->attributes[$field] = $this->getEncryptionService()
                                ->decrypt($this->attributes[$field])->await();

                            // Log decryption event
                            $this->logEncryptionEvent('decrypt', $field)->await();
                        } catch (\Exception $e) {
                            // Try with historical keys
                            try {
                                $this->attributes[$field] = $this->getEncryptionService()
                                    ->decryptWithHistoricalKeys($this->attributes[$field])->await();

                                $this->logEncryptionEvent('decrypt_historical', $field)->await();
                            } catch (\Exception $e) {
                                // Log failed decryption
                                $this->logEncryptionEvent('decrypt_failed', $field, false)->await();
                                $this->attributes[$field] = '[DECRYPTION_FAILED]';
                            }
                        }
                    }
                }
            }
        });
    }

    /**
     * Get masked version of attributes for logging
     */
    public function getMaskedAttributes(): array
    {
        $masked = $this->attributes;
        $maskedFields = $this->maskedFields();
        $dataMasking = $this->getDataMasking();

        foreach ($maskedFields as $field => $type) {
            if (isset($masked[$field])) {
                $masked[$field] = $dataMasking->maskData($masked[$field], $type);
            }
        }

        return $masked;
    }

    /**
     * Check if a value is encrypted
     */
    protected function isEncrypted(string $value): bool
    {
        // Simple check - encrypted values are base64 encoded and have minimum length
        return strlen($value) > 50 && base64_encode(base64_decode($value, true)) === $value;
    }

    /**
     * Log encryption/decryption events
     */
    protected function logEncryptionEvent(string $operation, string $field, bool $success = true): Future
    {
        return async(function () use ($operation, $field, $success) {
            $userId = $this->getCurrentUserId();

            $this->getAuditLogger()->logEncryptionEvent(
                $operation,
                static::class . '::' . $field,
                $userId,
                $success
            )->await();
        });
    }

    /**
     * Log data access events
     */
    protected function logDataAccess(string $action): Future
    {
        return async(function () use ($action) {
            $userId = $this->getCurrentUserId();
            $recordId = $this->getPrimaryKey() ?? 'unknown';
            $sensitiveFields = array_merge($this->encryptedFields(), array_keys($this->maskedFields()));

            $this->getAuditLogger()->logDataAccess(
                $action,
                static::tableName(),
                (string) $recordId,
                $userId,
                $sensitiveFields
            )->await();
        });
    }

    /**
     * Get current user ID for audit logging
     */
    protected function getCurrentUserId(): string
    {
        // Try to get from various sources
        if (isset($_SESSION['user_id'])) {
            return (string) $_SESSION['user_id'];
        }

        if (function_exists('auth') && auth()->check()) {
            return (string) auth()->id();
        }

        return 'system';
    }

    /**
     * Override save method to handle encryption
     */
    public function save(bool $validate = true): Future
    {
        return async(function () use ($validate) {
            // Encrypt before saving
            $this->encryptAttributes()->await();

            // Log data access
            $action = $this->isNewRecord ? 'create' : 'update';
            $this->logDataAccess($action)->await();

            // Call parent save method
            return parent::save($validate)->await();
        });
    }

    /**
     * Override find methods to handle decryption
     */
    public static function find(): Future
    {
        return async(function () {
            $models = parent::find()->await();

            if (is_array($models)) {
                foreach ($models as $model) {
                    if ($model instanceof self) {
                        $model->decryptAttributes()->await();
                        $model->logDataAccess('read')->await();
                    }
                }
            } elseif ($models instanceof self) {
                $models->decryptAttributes()->await();
                $models->logDataAccess('read')->await();
            }

            return $models;
        });
    }

    /**
     * Override findOne to handle decryption
     */
    public static function findOne($condition): Future
    {
        return async(function () use ($condition) {
            $model = parent::findOne($condition)->await();

            if ($model instanceof self) {
                $model->decryptAttributes()->await();
                $model->logDataAccess('read')->await();
            }

            return $model;
        });
    }

    /**
     * Override delete to log access
     */
    public function delete(): Future
    {
        return async(function () {
            $this->logDataAccess('delete')->await();
            return parent::delete()->await();
        });
    }

    /**
     * Get searchable (non-encrypted) version of field
     */
    public function getSearchableValue(string $field): ?string
    {
        if (!in_array($field, $this->encryptedFields())) {
            return $this->attributes[$field] ?? null;
        }

        // For encrypted fields, we might need to store a hash for searching
        $value = $this->attributes[$field] ?? null;
        if ($value === null) {
            return null;
        }

        // Return hash of the original value for searching
        return hash('sha256', $value);
    }
}
