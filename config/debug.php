<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | When debug mode is enabled, detailed error pages will be shown on
    | exceptions and errors. This should be disabled in production.
    |
    */
    'debug' => env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Performance Profiler
    |--------------------------------------------------------------------------
    |
    | The performance profiler collects timing and memory usage data
    | for analysis and optimization.
    |
    */
    'profiler' => [
        'enabled' => env('DEBUG_PROFILER_ENABLED', true),
        'collect_timers' => true,
        'collect_memory_snapshots' => true,
        'collect_query_data' => true,
        'max_snapshots' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Coroutine Debugger
    |--------------------------------------------------------------------------
    |
    | The coroutine debugger monitors coroutine execution and helps
    | identify performance issues and failures.
    |
    */
    'coroutine_debugger' => [
        'enabled' => env('DEBUG_COROUTINE_ENABLED', true),
        'slow_threshold' => env('DEBUG_SLOW_COROUTINE_THRESHOLD', 1.0), // seconds
        'collect_stacks' => true,
        'max_stack_depth' => 20,
        'monitor_interval' => 5, // seconds
        'cleanup_interval' => 3600, // seconds (1 hour)
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Analyzer
    |--------------------------------------------------------------------------
    |
    | The query analyzer monitors database queries and identifies
    | performance issues like slow queries and N+1 problems.
    |
    */
    'query_analyzer' => [
        'enabled' => env('DEBUG_QUERY_ANALYZER_ENABLED', true),
        'slow_threshold' => env('DEBUG_SLOW_QUERY_THRESHOLD', 0.1), // seconds
        'max_queries' => 1000,
        'detect_n_plus_one' => true,
        'detect_duplicates' => true,
        'collect_backtraces' => true,
        'backtrace_depth' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Handler
    |--------------------------------------------------------------------------
    |
    | Enhanced error handler with detailed debugging information
    | including source code context and stack traces.
    |
    */
    'error_handler' => [
        'enabled' => env('DEBUG_ERROR_HANDLER_ENABLED', true),
        'show_source_code' => true,
        'source_code_lines' => 10,
        'collect_stack_traces' => true,
        'log_errors' => true,
        'display_errors' => env('APP_DEBUG', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware for collecting request performance data and adding
    | debug headers to responses.
    |
    */
    'middleware' => [
        'enabled' => env('DEBUG_MIDDLEWARE_ENABLED', true),
        'profile_requests' => true,
        'add_debug_headers' => env('APP_DEBUG', false),
        'log_slow_requests' => true,
        'slow_request_threshold' => env('DEBUG_SLOW_REQUEST_THRESHOLD', 1.0), // seconds
        'collect_request_data' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Dashboard
    |--------------------------------------------------------------------------
    |
    | Configuration for the real-time monitoring and debug dashboard.
    |
    */
    'dashboard' => [
        'enabled' => env('DEBUG_DASHBOARD_ENABLED', true),
        'auth_enabled' => env('DEBUG_DASHBOARD_AUTH', true),
        'auth_token' => env('DEBUG_DASHBOARD_TOKEN'),
        'refresh_interval' => 5000, // milliseconds
        'max_log_entries' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Export Settings
    |--------------------------------------------------------------------------
    |
    | Settings for exporting debug data to various formats.
    |
    */
    'export' => [
        'formats' => ['json', 'csv', 'html'],
        'max_file_size' => 50 * 1024 * 1024, // 50MB
        'compression' => true,
        'retention_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Thresholds
    |--------------------------------------------------------------------------
    |
    | Various performance thresholds for alerting and monitoring.
    |
    */
    'thresholds' => [
        'memory_usage_warning' => 0.8, // 80% of memory limit
        'memory_usage_critical' => 0.95, // 95% of memory limit
        'cpu_usage_warning' => 0.7, // 70% CPU usage
        'cpu_usage_critical' => 0.9, // 90% CPU usage
        'disk_usage_warning' => 0.8, // 80% disk usage
        'disk_usage_critical' => 0.95, // 95% disk usage
        'response_time_warning' => 2.0, // 2 seconds
        'response_time_critical' => 5.0, // 5 seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for debug logging including log levels and destinations.
    |
    */
    'logging' => [
        'level' => env('DEBUG_LOG_LEVEL', 'debug'),
        'channels' => [
            'file' => [
                'enabled' => true,
                'path' => 'storage/logs/debug.log',
                'max_size' => 10 * 1024 * 1024, // 10MB
                'rotate' => true,
            ],
            'syslog' => [
                'enabled' => false,
                'facility' => LOG_USER,
            ],
            'elasticsearch' => [
                'enabled' => false,
                'host' => env('ELASTICSEARCH_HOST', 'localhost:9200'),
                'index' => 'hybridphp-debug',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Security settings for debug tools to prevent information disclosure.
    |
    */
    'security' => [
        'allowed_ips' => env('DEBUG_ALLOWED_IPS', '127.0.0.1,::1'),
        'mask_sensitive_data' => true,
        'sensitive_headers' => [
            'authorization',
            'cookie',
            'x-api-key',
            'x-auth-token',
            'x-csrf-token',
        ],
        'sensitive_params' => [
            'password',
            'token',
            'secret',
            'key',
            'auth',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Integration Settings
    |--------------------------------------------------------------------------
    |
    | Settings for integrating with external monitoring and debugging tools.
    |
    */
    'integrations' => [
        'xdebug' => [
            'enabled' => extension_loaded('xdebug'),
            'profiler_output_dir' => 'storage/debug/xdebug',
        ],
        'blackfire' => [
            'enabled' => extension_loaded('blackfire'),
        ],
        'newrelic' => [
            'enabled' => extension_loaded('newrelic'),
        ],
        'datadog' => [
            'enabled' => false,
            'api_key' => env('DATADOG_API_KEY'),
        ],
        'sentry' => [
            'enabled' => false,
            'dsn' => env('SENTRY_DSN'),
        ],
    ],
];