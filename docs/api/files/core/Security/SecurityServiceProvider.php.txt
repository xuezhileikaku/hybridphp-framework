<?php

declare(strict_types=1);

namespace HybridPHP\Core\Security;

use HybridPHP\Core\Container;
use HybridPHP\Core\Database\DatabaseInterface;
use HybridPHP\Core\LoggerInterface;
use HybridPHP\Core\Cache\CacheInterface;

/**
 * Security service provider for data encryption and audit logging
 */
class SecurityServiceProvider
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Register security services
     */
    public function register(): void
    {
        // Register encryption service
        $this->container->singleton(EncryptionService::class, function () {
            $encryptionKey = $_ENV['APP_ENCRYPTION_KEY'] ?? $this->generateDefaultKey();
            return new EncryptionService($encryptionKey);
        });

        // Register key manager
        $this->container->singleton(KeyManager::class, function () {
            $db = $this->container->get(DatabaseInterface::class);
            $encryption = $this->container->get(EncryptionService::class);
            return new KeyManager($db, $encryption);
        });

        // Register audit logger
        $this->container->singleton(AuditLogger::class, function () {
            $db = $this->container->get(DatabaseInterface::class);
            $logger = $this->container->get(LoggerInterface::class);
            $encryption = $this->container->get(EncryptionService::class);
            return new AuditLogger($db, $logger, $encryption);
        });

        // Register data masking service
        $this->container->singleton(DataMasking::class, function () {
            return new DataMasking();
        });

        // Register encrypted cache wrapper
        $this->container->singleton('cache.encrypted', function () {
            $cache = $this->container->get(CacheInterface::class);
            $encryption = $this->container->get(EncryptionService::class);
            $auditLogger = $this->container->get(AuditLogger::class);
            return new EncryptedCache($cache, $encryption, $auditLogger);
        });

        // Register TLS configuration service
        $this->container->singleton(TlsConfiguration::class, function () {
            return new TlsConfiguration();
        });
    }

    /**
     * Boot security services
     */
    public function boot(): void
    {
        // Initialize database tables if needed
        $this->initializeTables();
        
        // Set up automatic key rotation if configured
        $this->setupKeyRotation();
        
        // Configure audit log cleanup
        $this->setupAuditLogCleanup();
    }

    /**
     * Initialize required database tables
     */
    private function initializeTables(): void
    {
        try {
            $keyManager = $this->container->get(KeyManager::class);
            $auditLogger = $this->container->get(AuditLogger::class);
            
            // Create tables asynchronously
            \Amp\async(function () use ($keyManager, $auditLogger) {
                $keyManager->createKeyTable()->await();
                $auditLogger->createAuditTable()->await();
            });
        } catch (\Exception $e) {
            error_log("Failed to initialize security tables: " . $e->getMessage());
        }
    }

    /**
     * Setup automatic key rotation
     */
    private function setupKeyRotation(): void
    {
        $rotationInterval = $_ENV['KEY_ROTATION_INTERVAL'] ?? 86400; // 24 hours default
        
        if ($rotationInterval > 0) {
            // Schedule key rotation (this would typically be handled by a cron job or task scheduler)
            // For now, we'll just log that rotation should be set up
            error_log("Key rotation should be configured to run every {$rotationInterval} seconds");
        }
    }

    /**
     * Setup audit log cleanup
     */
    private function setupAuditLogCleanup(): void
    {
        $retentionDays = $_ENV['AUDIT_LOG_RETENTION_DAYS'] ?? 90;
        
        // Schedule cleanup (this would typically be handled by a cron job)
        error_log("Audit log cleanup should be configured to run daily, keeping {$retentionDays} days of logs");
    }

    /**
     * Generate default encryption key if none provided
     */
    private function generateDefaultKey(): string
    {
        $key = bin2hex(random_bytes(32));
        
        // Log warning about using generated key
        error_log("WARNING: Using generated encryption key. Set APP_ENCRYPTION_KEY in environment for production.");
        
        return $key;
    }

    /**
     * Get encryption service instance
     */
    public function getEncryptionService(): EncryptionService
    {
        return $this->container->get(EncryptionService::class);
    }

    /**
     * Get audit logger instance
     */
    public function getAuditLogger(): AuditLogger
    {
        return $this->container->get(AuditLogger::class);
    }

    /**
     * Get data masking service instance
     */
    public function getDataMasking(): DataMasking
    {
        return $this->container->get(DataMasking::class);
    }

    /**
     * Get encrypted cache instance
     */
    public function getEncryptedCache(): EncryptedCache
    {
        return $this->container->get('cache.encrypted');
    }
}