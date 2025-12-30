<?php

return [
    'default' => env('CACHE_DRIVER', 'multilevel'),
    
    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_DATABASE', 0),
            'password' => env('REDIS_PASSWORD', null),
            'prefix' => env('CACHE_PREFIX', 'hybridphp_cache_'),
            'pool' => [
                'min' => 5,
                'max' => 20,
                'idle_timeout' => 60,
            ],
            // Distributed Redis nodes for consistent hashing
            'nodes' => [
                ['host' => env('REDIS_HOST', '127.0.0.1'), 'port' => env('REDIS_PORT', 6379)],
                // Add more nodes for distribution
                // ['host' => '127.0.0.1', 'port' => 6380],
                // ['host' => '127.0.0.1', 'port' => 6381],
            ],
        ],
        
        'redis_cluster' => [
            'driver' => 'redis',
            'prefix' => env('CACHE_PREFIX', 'hybridphp_cache_'),
            'nodes' => [
                ['host' => env('REDIS_NODE1_HOST', '127.0.0.1'), 'port' => env('REDIS_NODE1_PORT', 6379)],
                ['host' => env('REDIS_NODE2_HOST', '127.0.0.1'), 'port' => env('REDIS_NODE2_PORT', 6380)],
                ['host' => env('REDIS_NODE3_HOST', '127.0.0.1'), 'port' => env('REDIS_NODE3_PORT', 6381)],
            ],
        ],
        
        'file' => [
            'driver' => 'file',
            'path' => 'storage/cache',
            'prefix' => env('CACHE_PREFIX', 'hybridphp_cache_'),
        ],
        
        'memory' => [
            'driver' => 'memory',
            'max_size' => env('CACHE_MEMORY_SIZE', 100 * 1024 * 1024), // 100MB default
            'prefix' => env('CACHE_PREFIX', 'hybridphp_cache_'),
        ],
        
        // Multi-level cache (L1: Memory, L2: Redis)
        'multilevel' => [
            'driver' => 'multilevel',
            'l1' => [
                'driver' => 'memory',
                'max_size' => 50 * 1024 * 1024, // 50MB for L1
            ],
            'l2' => [
                'driver' => 'redis',
                'host' => env('REDIS_HOST', '127.0.0.1'),
                'port' => env('REDIS_PORT', 6379),
                'database' => env('REDIS_DATABASE', 0),
                'password' => env('REDIS_PASSWORD', null),
            ],
            'l1_ttl_ratio' => 0.1, // L1 TTL is 10% of L2 TTL
            'write_through' => true,
            'read_through' => true,
            'prefix' => env('CACHE_PREFIX', 'hybridphp_cache_'),
        ],
    ],
    
    'ttl' => [
        'default' => 3600, // 1 hour
        'short' => 300,    // 5 minutes
        'long' => 86400,   // 24 hours
        'null_protection' => 300, // 5 minutes for null values
    ],
    
    // Anti-pattern protection settings
    'protection' => [
        'stampede' => [
            'enabled' => true,
            'lock_timeout' => 30, // seconds
        ],
        'penetration' => [
            'enabled' => true,
            'null_ttl' => 300, // Cache null results for 5 minutes
        ],
        'avalanche' => [
            'enabled' => true,
            'jitter_range' => 0.1, // Add 10% random jitter to TTL
        ],
    ],
    
    // Cache warming settings
    'warming' => [
        'enabled' => env('CACHE_WARMING_ENABLED', false),
        'batch_size' => 100,
        'delay_between_batches' => 100, // milliseconds
    ],
];