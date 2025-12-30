<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Performance Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration options for the HybridPHP
    | performance monitoring and alerting system.
    |
    */

    'enabled' => env('MONITORING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Metrics Collection
    |--------------------------------------------------------------------------
    |
    | Configuration for metrics collection including intervals, buckets,
    | and storage limits.
    |
    */
    'metrics' => [
        'collection_interval' => env('METRICS_COLLECTION_INTERVAL', 10), // seconds
        'histogram_buckets' => [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0],
        'max_metrics' => env('METRICS_MAX_COUNT', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert Management
    |--------------------------------------------------------------------------
    |
    | Configuration for alert processing, retention, and cooldown periods.
    |
    */
    'alerts' => [
        'processing_interval' => env('ALERTS_PROCESSING_INTERVAL', 10), // seconds
        'alert_retention' => env('ALERTS_RETENTION', 3600), // 1 hour
        'max_alerts' => env('ALERTS_MAX_COUNT', 1000),
        'cooldown_period' => env('ALERTS_COOLDOWN', 300), // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for performance monitoring thresholds and intervals.
    |
    */
    'performance' => [
        'monitoring_interval' => env('PERFORMANCE_MONITORING_INTERVAL', 5), // seconds
        'request_timeout_threshold' => env('PERFORMANCE_REQUEST_TIMEOUT', 30.0), // seconds
        'memory_threshold' => env('PERFORMANCE_MEMORY_THRESHOLD', 0.9), // 90%
        'cpu_threshold' => env('PERFORMANCE_CPU_THRESHOLD', 0.8), // 80%
        'coroutine_threshold' => env('PERFORMANCE_COROUTINE_THRESHOLD', 1000), // max coroutines
        'response_time_percentiles' => [50, 90, 95, 99],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Dashboard
    |--------------------------------------------------------------------------
    |
    | Configuration for the real-time monitoring dashboard.
    |
    */
    'dashboard' => [
        'auth_enabled' => env('DASHBOARD_AUTH_ENABLED', true),
        'auth_token' => env('DASHBOARD_AUTH_TOKEN'),
        'refresh_interval' => env('DASHBOARD_REFRESH_INTERVAL', 5000), // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Handlers
    |--------------------------------------------------------------------------
    |
    | Configuration for various notification channels when alerts are triggered.
    |
    */
    'notifications' => [
        'log' => [
            'enabled' => env('NOTIFICATIONS_LOG_ENABLED', true),
        ],

        'email' => [
            'enabled' => env('NOTIFICATIONS_EMAIL_ENABLED', false),
            'to' => env('NOTIFICATIONS_EMAIL_TO'),
            'from' => env('NOTIFICATIONS_EMAIL_FROM'),
            'subject_prefix' => env('NOTIFICATIONS_EMAIL_SUBJECT_PREFIX', '[HybridPHP Alert]'),
        ],

        'webhook' => [
            'enabled' => env('NOTIFICATIONS_WEBHOOK_ENABLED', false),
            'url' => env('NOTIFICATIONS_WEBHOOK_URL'),
            'headers' => [
                'User-Agent' => 'HybridPHP-Monitor/1.0',
            ],
            'timeout' => env('NOTIFICATIONS_WEBHOOK_TIMEOUT', 10),
        ],

        'slack' => [
            'enabled' => env('NOTIFICATIONS_SLACK_ENABLED', false),
            'webhook_url' => env('NOTIFICATIONS_SLACK_WEBHOOK_URL'),
            'channel' => env('NOTIFICATIONS_SLACK_CHANNEL', '#alerts'),
            'username' => env('NOTIFICATIONS_SLACK_USERNAME', 'HybridPHP Monitor'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prometheus Integration
    |--------------------------------------------------------------------------
    |
    | Configuration for Prometheus metrics export.
    |
    */
    'prometheus' => [
        'enabled' => env('PROMETHEUS_ENABLED', true),
        'endpoint' => env('PROMETHEUS_ENDPOINT', '/monitoring/prometheus'),
        'namespace' => env('PROMETHEUS_NAMESPACE', 'hybridphp'),
        'labels' => [
            'instance' => env('PROMETHEUS_INSTANCE', gethostname()),
            'version' => env('APP_VERSION', '1.0.0'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ELK Integration
    |--------------------------------------------------------------------------
    |
    | Configuration for ELK (Elasticsearch, Logstash, Kibana) integration.
    |
    */
    'elk' => [
        'enabled' => env('ELK_ENABLED', true),
        'index_prefix' => env('ELK_INDEX_PREFIX', 'hybridphp-metrics'),
        'timestamp_field' => '@timestamp',
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Business Metrics
    |--------------------------------------------------------------------------
    |
    | Define custom business metrics that should be tracked.
    |
    */
    'custom_metrics' => [
        // Example custom metrics
        'user_registrations' => [
            'type' => 'counter',
            'description' => 'Total user registrations',
            'labels' => ['source', 'plan'],
        ],
        'order_value' => [
            'type' => 'histogram',
            'description' => 'Order values in USD',
            'buckets' => [10, 50, 100, 500, 1000, 5000],
        ],
        'active_sessions' => [
            'type' => 'gauge',
            'description' => 'Number of active user sessions',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Analysis
    |--------------------------------------------------------------------------
    |
    | Configuration for performance analysis and reporting.
    |
    */
    'analysis' => [
        'enabled' => env('PERFORMANCE_ANALYSIS_ENABLED', true),
        'report_interval' => env('PERFORMANCE_REPORT_INTERVAL', 3600), // 1 hour
        'trend_analysis_window' => env('PERFORMANCE_TREND_WINDOW', 86400), // 24 hours
        'anomaly_detection' => env('PERFORMANCE_ANOMALY_DETECTION', true),
        'baseline_calculation_period' => env('PERFORMANCE_BASELINE_PERIOD', 604800), // 7 days
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for metrics and alert data storage.
    |
    */
    'storage' => [
        'driver' => env('MONITORING_STORAGE_DRIVER', 'memory'), // memory, redis, database
        'redis' => [
            'connection' => env('MONITORING_REDIS_CONNECTION', 'default'),
            'key_prefix' => env('MONITORING_REDIS_PREFIX', 'monitoring:'),
            'ttl' => env('MONITORING_REDIS_TTL', 86400), // 24 hours
        ],
        'database' => [
            'connection' => env('MONITORING_DB_CONNECTION', 'default'),
            'table_prefix' => env('MONITORING_DB_PREFIX', 'monitoring_'),
        ],
    ],
];