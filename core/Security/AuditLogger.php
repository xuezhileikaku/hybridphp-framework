<?php

declare(strict_types=1);

namespace HybridPHP\Core\Security;

use Amp\Future;
use HybridPHP\Core\Database\DatabaseInterface;
use HybridPHP\Core\LoggerInterface;
use function Amp\async;

/**
 * Audit logging service for security events
 */
class AuditLogger
{
    private DatabaseInterface $db;
    private LoggerInterface $logger;
    private EncryptionService $encryption;
    private string $auditTable = 'audit_logs';

    public function __construct(
        DatabaseInterface $db,
        LoggerInterface $logger,
        EncryptionService $encryption
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->encryption = $encryption;
    }

    /**
     * Log security event
     */
    public function logSecurityEvent(
        string $event,
        string $userId,
        array $context = [],
        string $severity = 'info'
    ): Future {
        return async(function () use ($event, $userId, $context, $severity) {
            $auditData = [
                'event_type' => $event,
                'user_id' => $userId,
                'ip_address' => $context['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $context['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'context' => json_encode($context),
                'severity' => $severity,
                'timestamp' => date('Y-m-d H:i:s'),
                'session_id' => $context['session_id'] ?? (session_id() ?: 'none'),
                'request_id' => $context['request_id'] ?? uniqid('req_', true)
            ];

            // Store in database
            $this->storeAuditLog($auditData)->await();

            // Also log to application logger
            $this->logger->log($severity, "Security Event: {$event}", [
                'user_id' => $userId,
                'ip_address' => $auditData['ip_address'],
                'context' => $context
            ]);

            return true;
        });
    }

    /**
     * Log data access event
     */
    public function logDataAccess(
        string $action,
        string $table,
        string $recordId,
        string $userId,
        array $sensitiveFields = []
    ): Future {
        return async(function () use ($action, $table, $recordId, $userId, $sensitiveFields) {
            $context = [
                'action' => $action,
                'table' => $table,
                'record_id' => $recordId,
                'sensitive_fields' => $sensitiveFields,
                'timestamp' => microtime(true)
            ];

            $this->logSecurityEvent('data_access', $userId, $context, 'info')->await();
            return true;
        });
    }

    /**
     * Log authentication event
     */
    public function logAuthEvent(
        string $event,
        string $userId,
        bool $success,
        array $context = []
    ): Future {
        return async(function () use ($event, $userId, $success, $context) {
            $context['success'] = $success;
            $context['auth_method'] = $context['auth_method'] ?? 'unknown';

            $severity = $success ? 'info' : 'warning';

            $this->logSecurityEvent("auth_{$event}", $userId, $context, $severity)->await();
            return true;
        });
    }

    /**
     * Log encryption/decryption events
     */
    public function logEncryptionEvent(
        string $operation,
        string $dataType,
        string $userId,
        bool $success = true
    ): Future {
        return async(function () use ($operation, $dataType, $userId, $success) {
            $context = [
                'operation' => $operation,
                'data_type' => $dataType,
                'success' => $success
            ];

            $severity = $success ? 'info' : 'error';

            $this->logSecurityEvent('encryption_operation', $userId, $context, $severity)->await();
            return true;
        });
    }

    /**
     * Store audit log in database
     */
    private function storeAuditLog(array $auditData): Future
    {
        return async(function () use ($auditData) {
            // Encrypt sensitive context data
            if (!empty($auditData['context'])) {
                $auditData['context'] = $this->encryption->encrypt($auditData['context'])->await();
            }

            $sql = "
                INSERT INTO {$this->auditTable} 
                (event_type, user_id, ip_address, user_agent, context, severity, timestamp, session_id, request_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $this->db->execute($sql, [
                $auditData['event_type'],
                $auditData['user_id'],
                $auditData['ip_address'],
                $auditData['user_agent'],
                $auditData['context'],
                $auditData['severity'],
                $auditData['timestamp'],
                $auditData['session_id'],
                $auditData['request_id']
            ])->await();

            return true;
        });
    }

    /**
     * Query audit logs
     */
    public function queryAuditLogs(
        array $filters = [],
        int $limit = 100,
        int $offset = 0
    ): Future {
        return async(function () use ($filters, $limit, $offset) {
            $where = [];
            $params = [];

            if (!empty($filters['user_id'])) {
                $where[] = 'user_id = ?';
                $params[] = $filters['user_id'];
            }

            if (!empty($filters['event_type'])) {
                $where[] = 'event_type = ?';
                $params[] = $filters['event_type'];
            }

            if (!empty($filters['severity'])) {
                $where[] = 'severity = ?';
                $params[] = $filters['severity'];
            }

            if (!empty($filters['date_from'])) {
                $where[] = 'timestamp >= ?';
                $params[] = $filters['date_from'];
            }

            if (!empty($filters['date_to'])) {
                $where[] = 'timestamp <= ?';
                $params[] = $filters['date_to'];
            }

            $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

            $sql = "
                SELECT * FROM {$this->auditTable} 
                {$whereClause}
                ORDER BY timestamp DESC 
                LIMIT ? OFFSET ?
            ";

            $params[] = $limit;
            $params[] = $offset;

            $results = $this->db->query($sql, $params)->await();

            // Decrypt context data for results
            foreach ($results as &$result) {
                if (!empty($result['context'])) {
                    try {
                        $result['context'] = $this->encryption->decrypt($result['context'])->await();
                        $result['context'] = json_decode($result['context'], true);
                    } catch (\Exception $e) {
                        $result['context'] = ['error' => 'Failed to decrypt context'];
                    }
                }
            }

            return $results;
        });
    }

    /**
     * Create audit log table
     */
    public function createAuditTable(): Future
    {
        return async(function () {
            $sql = "
                CREATE TABLE IF NOT EXISTS {$this->auditTable} (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    event_type VARCHAR(100) NOT NULL,
                    user_id VARCHAR(255) NOT NULL,
                    ip_address VARCHAR(45) NOT NULL,
                    user_agent TEXT,
                    context TEXT,
                    severity ENUM('debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency') DEFAULT 'info',
                    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    session_id VARCHAR(255),
                    request_id VARCHAR(255),
                    INDEX idx_event_type (event_type),
                    INDEX idx_user_id (user_id),
                    INDEX idx_timestamp (timestamp),
                    INDEX idx_severity (severity),
                    INDEX idx_session (session_id)
                )
            ";

            $this->db->execute($sql)->await();
            return true;
        });
    }

    /**
     * Clean old audit logs
     */
    public function cleanOldLogs(int $daysToKeep = 90): Future
    {
        return async(function () use ($daysToKeep) {
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));

            $result = $this->db->execute(
                "DELETE FROM {$this->auditTable} WHERE timestamp < ?",
                [$cutoffDate]
            )->await();

            $this->logger->info("Cleaned old audit logs", [
                'cutoff_date' => $cutoffDate,
                'rows_deleted' => $result
            ]);

            return $result;
        });
    }
}
