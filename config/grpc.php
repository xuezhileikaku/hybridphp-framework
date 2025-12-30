<?php

/**
 * gRPC Configuration
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Enable gRPC
    |--------------------------------------------------------------------------
    |
    | Enable or disable gRPC server and client functionality.
    |
    */
    'enabled' => env('GRPC_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the gRPC server.
    |
    */
    'server' => [
        'host' => env('GRPC_HOST', '0.0.0.0'),
        'port' => (int) env('GRPC_PORT', 50051),
        'maxConcurrentStreams' => (int) env('GRPC_MAX_CONCURRENT_STREAMS', 100),
        'maxMessageSize' => (int) env('GRPC_MAX_MESSAGE_SIZE', 4 * 1024 * 1024), // 4MB
        'keepaliveTime' => (int) env('GRPC_KEEPALIVE_TIME', 7200),
        'keepaliveTimeout' => (int) env('GRPC_KEEPALIVE_TIMEOUT', 20),
        'compression' => env('GRPC_COMPRESSION', 'gzip'),
        'reflection' => env('GRPC_REFLECTION', true),
        
        // TLS configuration (null to disable)
        'tls' => env('GRPC_TLS_ENABLED', false) ? [
            'cert' => env('GRPC_TLS_CERT', storage_path('ssl/server.crt')),
            'key' => env('GRPC_TLS_KEY', storage_path('ssl/server.key')),
        ] : null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Configuration
    |--------------------------------------------------------------------------
    |
    | Default configuration for gRPC clients.
    |
    */
    'client' => [
        'host' => env('GRPC_CLIENT_HOST', 'localhost'),
        'port' => (int) env('GRPC_CLIENT_PORT', 50051),
        'timeout' => (int) env('GRPC_CLIENT_TIMEOUT', 30),
        'retries' => (int) env('GRPC_CLIENT_RETRIES', 3),
        'retryDelay' => (float) env('GRPC_CLIENT_RETRY_DELAY', 0.1),
        'tls' => env('GRPC_CLIENT_TLS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Discovery
    |--------------------------------------------------------------------------
    |
    | Configuration for service discovery integration.
    |
    */
    'discovery' => [
        // Driver: memory, consul, etcd
        'driver' => env('GRPC_DISCOVERY_DRIVER', 'memory'),

        // Consul configuration
        'consul' => [
            'host' => env('CONSUL_HOST', 'localhost'),
            'port' => (int) env('CONSUL_PORT', 8500),
            'scheme' => env('CONSUL_SCHEME', 'http'),
            'token' => env('CONSUL_TOKEN'),
            'datacenter' => env('CONSUL_DATACENTER'),
            'healthCheckInterval' => env('CONSUL_HEALTH_CHECK_INTERVAL', '10s'),
            'deregisterCriticalServiceAfter' => env('CONSUL_DEREGISTER_AFTER', '1m'),
        ],

        // etcd configuration (for future implementation)
        'etcd' => [
            'hosts' => explode(',', env('ETCD_HOSTS', 'localhost:2379')),
            'username' => env('ETCD_USERNAME'),
            'password' => env('ETCD_PASSWORD'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Load Balancing
    |--------------------------------------------------------------------------
    |
    | Load balancing strategy for client-side load balancing.
    | Options: round_robin, weighted, least_connections, consistent_hash
    |
    */
    'loadBalancer' => env('GRPC_LOAD_BALANCER', 'round_robin'),

    /*
    |--------------------------------------------------------------------------
    | Interceptors
    |--------------------------------------------------------------------------
    |
    | List of interceptor classes to apply to gRPC calls.
    |
    */
    'interceptors' => [
        // \App\Grpc\Interceptors\LoggingInterceptor::class,
        // \App\Grpc\Interceptors\AuthInterceptor::class,
        // \App\Grpc\Interceptors\MetricsInterceptor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    |
    | List of gRPC service implementations to register.
    |
    */
    'services' => [
        // 'package.ServiceName' => \App\Grpc\Services\ServiceImplementation::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check
    |--------------------------------------------------------------------------
    |
    | gRPC health check configuration.
    |
    */
    'health' => [
        'enabled' => env('GRPC_HEALTH_ENABLED', true),
        'service' => 'grpc.health.v1.Health',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Metrics collection configuration.
    |
    */
    'metrics' => [
        'enabled' => env('GRPC_METRICS_ENABLED', true),
        'histogramBuckets' => [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10],
    ],
];
