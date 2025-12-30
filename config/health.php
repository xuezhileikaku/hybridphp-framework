<?php

declare(strict_types=1);

/**
 * Health Check and Monitoring Configuration
 */

// Simple env function for configuration
if (!function_exists('env')) {
    function env(string $key, $default = null) {
        return $_ENV[$key] ?? $default;
    }
}

return [
    /*
    |--------------------------------------------------------------------------
    | Health Check System
    |--------------------------------------------------------------------------
    |
    | Enable or disable the health check system
    |
    */
    'enabled' => $_ENV['HEALTH_CHECK_ENABLED'] ?? true,

    /*
    |--------------------------------------------------------------------------
    | Health Checks
    |--------------------------------------------------------------------------
    |
    | Configure which health checks to enable
    |
    */
    'checks' => [
        // Application health check
        'application' => env('HEALTH_CHECK_APPLICATION', true),
        
        // Database health check
        'database' => env('HEALTH_CHECK_DATABASE', true),
        
        // Cache health check
        'cache' => env('HEALTH_CHECK_CACHE', true),
        
        // External service health checks
        'external_services' => [
            // Example external service
            // 'api_service' => [
            //     'url' => env('EXTERNAL_API_HEALTH_URL', 'https://api.example.com/health'),
            //     'timeout' => 5,
            //     'critical' => false,
            //     'expected_status' => 200,
            //     'expected_content' => null,
            //     'headers' => [
            //         'Authorization' => 'Bearer ' . env('EXTERNAL_API_TOKEN', ''),
            //         'User-Agent' => 'HybridPHP-HealthCheck/1.0'
            //     ]
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the monitoring service
    |
    */
    'monitoring' => [
        // Enable monitoring service
        'enabled' => env('MONITORING_ENABLED', true),
        
        // Health check interval in seconds
        'check_interval' => env('HEALTH_CHECK_INTERVAL', 30),
        
        // Enable Prometheus metrics export
        'prometheus_enabled' => env('PROMETHEUS_ENABLED', true),
        
        // Enable ELK/JSON structured logging
        'elk_enabled' => env('ELK_ENABLED', true),
        
        // Enable alerting
        'alert_enabled' => env('ALERT_ENABLED', true),
        
        // Alert thresholds
        'alert_thresholds' => [
            // Response time threshold in seconds
            'response_time' => env('ALERT_RESPONSE_TIME_THRESHOLD', 5.0),
            
            // Error rate threshold (0.1 = 10%)
            'error_rate' => env('ALERT_ERROR_RATE_THRESHOLD', 0.1),
            
            // Memory usage threshold (0.9 = 90%)
            'memory_usage' => env('ALERT_MEMORY_USAGE_THRESHOLD', 0.9),
            
            // Disk usage threshold (0.9 = 90%)
            'disk_usage' => env('ALERT_DISK_USAGE_THRESHOLD', 0.9),
            
            // CPU load threshold
            'cpu_load' => env('ALERT_CPU_LOAD_THRESHOLD', 5.0),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Execution
    |--------------------------------------------------------------------------
    |
    | Configure how health checks are executed
    |
    */
    'execution' => [
        // Run health checks in parallel
        'parallel_execution' => env('HEALTH_CHECK_PARALLEL', true),
        
        // Stop execution on first critical failure
        'fail_fast' => env('HEALTH_CHECK_FAIL_FAST', false),
        
        // Cache health check results
        'cache_results' => env('HEALTH_CHECK_CACHE_RESULTS', true),
        
        // Cache TTL in seconds
        'cache_ttl' => env('HEALTH_CHECK_CACHE_TTL', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prometheus Configuration
    |--------------------------------------------------------------------------
    |
    | Configure Prometheus metrics export
    |
    */
    'prometheus' => [
        // Metric prefix
        'prefix' => env('PROMETHEUS_PREFIX', 'hybridphp'),
        
        // Include system metrics
        'include_system_metrics' => env('PROMETHEUS_INCLUDE_SYSTEM', true),
        
        // Include application metrics
        'include_app_metrics' => env('PROMETHEUS_INCLUDE_APP', true),
        
        // Custom labels to add to all metrics
        'labels' => [
            'service' => env('SERVICE_NAME', 'hybridphp'),
            'environment' => env('APP_ENV', 'production'),
            'version' => env('APP_VERSION', '1.0.0'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ELK Configuration
    |--------------------------------------------------------------------------
    |
    | Configure ELK/structured logging
    |
    */
    'elk' => [
        // Service name for ELK
        'service_name' => env('ELK_SERVICE_NAME', 'hybridphp'),
        
        // Include stack traces in error logs
        'include_traces' => env('ELK_INCLUDE_TRACES', true),
        
        // Log level for health check events
        'log_level' => env('ELK_LOG_LEVEL', 'info'),
        
        // Additional fields to include
        'additional_fields' => [
            'environment' => env('APP_ENV', 'production'),
            'version' => env('APP_VERSION', '1.0.0'),
            'hostname' => gethostname(),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert Configuration
    |--------------------------------------------------------------------------
    |
    | Configure alerting system
    |
    */
    'alerts' => [
        // Alert channels (future implementation)
        'channels' => [
            // 'slack' => [
            //     'webhook_url' => env('SLACK_WEBHOOK_URL'),
            //     'channel' => env('SLACK_CHANNEL', '#alerts'),
            // ],
            // 'email' => [
            //     'to' => env('ALERT_EMAIL_TO'),
            //     'from' => env('ALERT_EMAIL_FROM'),
            // ],
        ],
        
        // Alert cooldown in seconds (prevent spam)
        'cooldown' => env('ALERT_COOLDOWN', 300),
        
        // Maximum alerts per hour
        'rate_limit' => env('ALERT_RATE_LIMIT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Health Checks
    |--------------------------------------------------------------------------
    |
    | Register custom health check classes
    |
    */
    'custom_checks' => [
        // Example:
        // App\HealthChecks\CustomServiceHealthCheck::class,
    ],
];