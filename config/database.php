<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'hybridphp'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'pool' => [
                'min' => env('DB_POOL_MIN', 5),
                'max' => env('DB_POOL_MAX', 50),
                'idle_timeout' => 60,
                'max_lifetime' => 3600,
            ],
            'health_check_interval' => 30,
        ],
        
        'mysql_read' => [
            'driver' => 'mysql',
            'host' => env('DB_READ_HOST', env('DB_HOST', 'localhost')),
            'port' => env('DB_READ_PORT', env('DB_PORT', 3306)),
            'database' => env('DB_DATABASE', 'hybridphp'),
            'username' => env('DB_READ_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DB_READ_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'read' => true,
            'pool' => [
                'min' => env('DB_READ_POOL_MIN', 3),
                'max' => env('DB_READ_POOL_MAX', 30),
                'idle_timeout' => 60,
                'max_lifetime' => 3600,
            ],
            'health_check_interval' => 30,
        ],
        
        'mysql_write' => [
            'driver' => 'mysql',
            'host' => env('DB_WRITE_HOST', env('DB_HOST', 'localhost')),
            'port' => env('DB_WRITE_PORT', env('DB_PORT', 3306)),
            'database' => env('DB_DATABASE', 'hybridphp'),
            'username' => env('DB_WRITE_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DB_WRITE_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'write' => true,
            'pool' => [
                'min' => env('DB_WRITE_POOL_MIN', 2),
                'max' => env('DB_WRITE_POOL_MAX', 20),
                'idle_timeout' => 60,
                'max_lifetime' => 3600,
            ],
            'health_check_interval' => 30,
        ],
        
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'hybridphp'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'pool' => [
                'min' => env('DB_POOL_MIN', 5),
                'max' => env('DB_POOL_MAX', 50),
                'idle_timeout' => 60,
                'max_lifetime' => 3600,
            ],
            'health_check_interval' => 30,
        ],
    ],
    
    'migrations' => [
        'table' => 'migrations',
        'path' => 'database/migrations',
    ],
    
    'seeds' => [
        'path' => 'database/seeds',
    ],
];