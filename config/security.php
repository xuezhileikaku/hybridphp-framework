<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Encryption Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for data encryption and security features
    |
    */

    'encryption' => [
        // Default encryption key (should be set via APP_ENCRYPTION_KEY env var)
        'key' => $_ENV['APP_ENCRYPTION_KEY'] ?? null,
        
        // Encryption cipher
        'cipher' => 'aes-256-gcm',
        
        // Key rotation interval in seconds (24 hours default)
        'key_rotation_interval' => $_ENV['KEY_ROTATION_INTERVAL'] ?? 86400,
        
        // Number of historical keys to keep for decryption
        'key_history_limit' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for security audit logging
    |
    */

    'audit' => [
        // Enable audit logging
        'enabled' => $_ENV['AUDIT_LOGGING_ENABLED'] ?? true,
        
        // Audit log retention period in days
        'retention_days' => $_ENV['AUDIT_LOG_RETENTION_DAYS'] ?? 90,
        
        // Database table for audit logs
        'table' => 'audit_logs',
        
        // Events to log
        'log_events' => [
            'auth_login',
            'auth_logout',
            'auth_failed',
            'data_access',
            'data_create',
            'data_update',
            'data_delete',
            'encryption_operation',
            'key_rotation',
            'cache_access',
            'security_event',
        ],
        
        // Sensitive fields to encrypt in audit logs
        'encrypt_context' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Masking Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for data masking and anonymization
    |
    */

    'masking' => [
        // Default masking rules
        'rules' => [
            'email' => [
                'type' => 'email',
                'visible_chars' => 2,
            ],
            'phone' => [
                'type' => 'phone',
                'visible_chars' => 4,
            ],
            'credit_card' => [
                'type' => 'credit_card',
                'visible_chars' => 4,
            ],
            'ssn' => [
                'type' => 'ssn',
                'visible_chars' => 4,
            ],
            'name' => [
                'type' => 'name',
                'visible_chars' => 1,
            ],
        ],
        
        // Auto-detect sensitive data patterns
        'auto_detect' => true,
        
        // Anonymization mode (mask or anonymize)
        'mode' => 'mask', // 'mask' or 'anonymize'
    ],

    /*
    |--------------------------------------------------------------------------
    | TLS/SSL Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for TLS/SSL encryption
    |
    */

    'tls' => [
        // Enable TLS
        'enabled' => $_ENV['TLS_ENABLED'] ?? true,
        
        // Certificate paths
        'cert_path' => $_ENV['TLS_CERT_PATH'] ?? 'storage/ssl/server.crt',
        'key_path' => $_ENV['TLS_KEY_PATH'] ?? 'storage/ssl/server.key',
        'ca_path' => $_ENV['TLS_CA_PATH'] ?? null,
        
        // TLS version requirements
        'min_version' => 'TLSv1.2',
        'max_version' => 'TLSv1.3',
        
        // Cipher suites (secure defaults)
        'cipher_suites' => [
            'ECDHE-ECDSA-AES256-GCM-SHA384',
            'ECDHE-RSA-AES256-GCM-SHA384',
            'ECDHE-ECDSA-CHACHA20-POLY1305',
            'ECDHE-RSA-CHACHA20-POLY1305',
            'ECDHE-ECDSA-AES128-GCM-SHA256',
            'ECDHE-RSA-AES128-GCM-SHA256',
        ],
        
        // Security headers
        'security_headers' => [
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'",
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        ],
        
        // Certificate validation
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => $_ENV['TLS_ALLOW_SELF_SIGNED'] ?? false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Encryption Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for encrypted cache storage
    |
    */

    'cache_encryption' => [
        // Enable cache encryption
        'enabled' => $_ENV['CACHE_ENCRYPTION_ENABLED'] ?? true,
        
        // Sensitive key patterns (regex)
        'sensitive_patterns' => [
            '/user_session_/',
            '/auth_token_/',
            '/password_reset_/',
            '/sensitive_data_/',
            '/personal_info_/',
            '/payment_/',
            '/credit_card_/',
            '/ssn_/',
            '/private_/',
        ],
        
        // Auto-encrypt based on patterns
        'auto_encrypt' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Encryption Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for database field encryption
    |
    */

    'database_encryption' => [
        // Enable database field encryption
        'enabled' => $_ENV['DB_ENCRYPTION_ENABLED'] ?? true,
        
        // Default encrypted fields
        'default_encrypted_fields' => [
            'email',
            'phone',
            'ssn',
            'credit_card',
            'bank_account',
            'personal_notes',
            'private_data',
        ],
        
        // Generate search hashes for encrypted fields
        'generate_search_hashes' => true,
        
        // Hash algorithm for search hashes
        'search_hash_algorithm' => 'sha256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for security monitoring and alerting
    |
    */

    'monitoring' => [
        // Enable security monitoring
        'enabled' => $_ENV['SECURITY_MONITORING_ENABLED'] ?? true,
        
        // Failed login attempt threshold
        'failed_login_threshold' => 5,
        
        // Failed login time window (minutes)
        'failed_login_window' => 15,
        
        // Suspicious activity patterns
        'suspicious_patterns' => [
            'multiple_failed_logins',
            'unusual_access_patterns',
            'encryption_failures',
            'key_access_anomalies',
        ],
        
        // Alert channels
        'alert_channels' => [
            'log' => true,
            'email' => $_ENV['SECURITY_ALERT_EMAIL'] ?? null,
            'webhook' => $_ENV['SECURITY_ALERT_WEBHOOK'] ?? null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Compliance Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for compliance requirements (GDPR, HIPAA, etc.)
    |
    */

    'compliance' => [
        // Enable compliance features
        'enabled' => $_ENV['COMPLIANCE_ENABLED'] ?? true,
        
        // Data retention policies
        'data_retention' => [
            'audit_logs' => 90, // days
            'user_data' => 2555, // days (7 years)
            'session_data' => 30, // days
        ],
        
        // Right to be forgotten
        'right_to_be_forgotten' => [
            'enabled' => true,
            'anonymize_instead_of_delete' => true,
        ],
        
        // Data portability
        'data_portability' => [
            'enabled' => true,
            'export_formats' => ['json', 'csv', 'xml'],
        ],
        
        // Consent management
        'consent_management' => [
            'enabled' => true,
            'require_explicit_consent' => true,
            'track_consent_changes' => true,
        ],
    ],
];