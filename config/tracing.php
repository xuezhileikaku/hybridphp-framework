<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Distributed Tracing Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration options for the HybridPHP
    | distributed tracing system with OpenTelemetry support.
    |
    */

    'enabled' => env('TRACING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Service Name
    |--------------------------------------------------------------------------
    |
    | The name of your service as it will appear in tracing backends.
    |
    */
    'service_name' => env('TRACING_SERVICE_NAME', env('APP_NAME', 'hybridphp')),

    /*
    |--------------------------------------------------------------------------
    | Exporter Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the span exporter. Supported types:
    | - console: Output to stdout (for debugging)
    | - jaeger: Send to Jaeger collector
    | - zipkin: Send to Zipkin collector
    | - otlp: Send via OpenTelemetry Protocol
    |
    */
    'exporter' => [
        'type' => env('TRACING_EXPORTER', 'console'),

        // Jaeger configuration
        'endpoint' => env('TRACING_ENDPOINT', 'http://localhost:14268/api/traces'),
        
        // Additional tags for Jaeger
        'tags' => [
            'environment' => env('APP_ENV', 'production'),
            'version' => env('APP_VERSION', '1.0.0'),
        ],

        // Zipkin local endpoint IP
        'local_endpoint' => env('TRACING_LOCAL_ENDPOINT'),

        // OTLP resource attributes
        'resource_attributes' => [
            'deployment.environment' => env('APP_ENV', 'production'),
        ],

        // OTLP headers (e.g., for authentication)
        'headers' => [
            // 'Authorization' => 'Bearer ' . env('TRACING_AUTH_TOKEN'),
        ],

        // Console exporter pretty print
        'pretty_print' => env('TRACING_PRETTY_PRINT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracer Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the tracer behavior including batching and sampling.
    |
    */
    'tracer' => [
        // Number of spans to batch before exporting
        'batch_size' => env('TRACING_BATCH_SIZE', 100),

        // Interval between automatic flushes (seconds)
        'flush_interval' => env('TRACING_FLUSH_INTERVAL', 5.0),

        // Maximum number of spans to queue
        'max_queue_size' => env('TRACING_MAX_QUEUE_SIZE', 2048),

        // Sampling rate (0.0 to 1.0, where 1.0 = 100%)
        'sampling_rate' => env('TRACING_SAMPLING_RATE', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Tracing Configuration
    |--------------------------------------------------------------------------
    |
    | Configure HTTP request tracing behavior.
    |
    */
    'http' => [
        // Record request headers (may contain sensitive data)
        'record_headers' => env('TRACING_RECORD_HEADERS', false),

        // Record query parameters
        'record_query_params' => env('TRACING_RECORD_QUERY_PARAMS', true),

        // Headers to redact from traces
        'sensitive_headers' => [
            'authorization',
            'cookie',
            'x-api-key',
            'x-auth-token',
        ],

        // Paths to exclude from tracing
        'excluded_paths' => [
            '/health',
            '/healthz',
            '/ready',
            '/metrics',
            '/favicon.ico',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Tracing Configuration
    |--------------------------------------------------------------------------
    |
    | Configure database query tracing behavior.
    |
    */
    'database' => [
        // Record SQL statements
        'record_statement' => env('TRACING_RECORD_SQL', true),

        // Record query parameters (disabled by default for security)
        'record_parameters' => env('TRACING_RECORD_SQL_PARAMS', false),

        // Maximum statement length to record
        'max_statement_length' => env('TRACING_MAX_SQL_LENGTH', 2048),
    ],

    /*
    |--------------------------------------------------------------------------
    | Propagation Configuration
    |--------------------------------------------------------------------------
    |
    | Configure trace context propagation formats.
    | Multiple formats can be enabled for interoperability.
    |
    */
    'propagation' => [
        // W3C Trace Context (recommended)
        'w3c' => true,

        // B3 format (Zipkin compatibility)
        'b3' => true,

        // Jaeger format
        'jaeger' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Jaeger Configuration
    |--------------------------------------------------------------------------
    |
    | Specific configuration for Jaeger backend.
    |
    */
    'jaeger' => [
        'host' => env('JAEGER_HOST', 'localhost'),
        'port' => env('JAEGER_PORT', 14268),
        'agent_host' => env('JAEGER_AGENT_HOST', 'localhost'),
        'agent_port' => env('JAEGER_AGENT_PORT', 6831),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zipkin Configuration
    |--------------------------------------------------------------------------
    |
    | Specific configuration for Zipkin backend.
    |
    */
    'zipkin' => [
        'host' => env('ZIPKIN_HOST', 'localhost'),
        'port' => env('ZIPKIN_PORT', 9411),
    ],

    /*
    |--------------------------------------------------------------------------
    | OTLP Configuration
    |--------------------------------------------------------------------------
    |
    | Specific configuration for OpenTelemetry Protocol.
    |
    */
    'otlp' => [
        'host' => env('OTLP_HOST', 'localhost'),
        'port' => env('OTLP_PORT', 4318),
        'protocol' => env('OTLP_PROTOCOL', 'http/json'), // http/json or grpc
    ],
];
