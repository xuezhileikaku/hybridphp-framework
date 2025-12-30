<?php

return [
    'default' => env('LOG_CHANNEL', 'file'),
    
    'channels' => [
        'file' => [
            'driver' => 'file',
            'path' => env('LOG_FILE', 'storage/logs/app.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'max_files' => 30,
            'max_size' => 10 * 1024 * 1024, // 10MB
        ],
        
        'daily' => [
            'driver' => 'daily',
            'path' => 'storage/logs/app.log',
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
        ],
        
        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => LOG_USER,
        ],
        
        'stderr' => [
            'driver' => 'stderr',
            'level' => env('LOG_LEVEL', 'debug'),
        ],
        
        'stack' => [
            'driver' => 'stack',
            'channels' => ['file', 'stderr'],
        ],
        
        'elk' => [
            'driver' => 'elk',
            'host' => env('ELK_HOST', 'localhost'),
            'port' => env('ELK_PORT', 9200),
            'scheme' => env('ELK_SCHEME', 'http'),
            'index' => env('ELK_INDEX', 'hybridphp-logs'),
            'type' => '_doc',
            'level' => env('LOG_LEVEL', 'debug'),
            'buffer_size' => 100,
            'auth' => [
                'username' => env('ELK_USERNAME'),
                'password' => env('ELK_PASSWORD'),
            ],
        ],
        
        'kafka' => [
            'driver' => 'kafka',
            'brokers' => explode(',', env('KAFKA_BROKERS', 'localhost:9092')),
            'topic' => env('KAFKA_TOPIC', 'hybridphp-logs'),
            'level' => env('LOG_LEVEL', 'debug'),
            'buffer_size' => 100,
        ],
        
        'distributed' => [
            'driver' => 'stack',
            'channels' => ['file', 'elk'],
        ],
    ],
    
    'async' => [
        'enabled' => env('LOG_ASYNC', true),
        'buffer_size' => env('LOG_BUFFER_SIZE', 1000),
        'flush_interval' => env('LOG_FLUSH_INTERVAL', 5.0), // seconds
    ],
    
    'tracing' => [
        'enabled' => env('TRACING_ENABLED', true),
        'sample_rate' => env('TRACING_SAMPLE_RATE', 1.0), // 100% sampling
        'service_name' => env('TRACING_SERVICE_NAME', 'hybridphp'),
        'jaeger' => [
            'host' => env('JAEGER_HOST', 'localhost'),
            'port' => env('JAEGER_PORT', 14268),
        ],
    ],
    
    'archive' => [
        'enabled' => env('LOG_ARCHIVE_ENABLED', true),
        'directory' => 'storage/logs',
        'max_files' => env('LOG_MAX_FILES', 30),
        'max_size' => env('LOG_MAX_SIZE', 10 * 1024 * 1024), // 10MB
        'max_age_days' => env('LOG_MAX_AGE_DAYS', 30),
        'compress' => env('LOG_COMPRESS', true),
        'compression_format' => env('LOG_COMPRESSION_FORMAT', 'gzip'), // gzip or zip
        'interval' => env('LOG_ARCHIVE_INTERVAL', 3600), // seconds
    ],
    
    'filters' => [
        'sensitive_fields' => [
            'password',
            'token',
            'secret',
            'key',
            'authorization',
            'cookie',
        ],
        'max_string_length' => 1000,
        'max_array_depth' => 10,
    ],
];