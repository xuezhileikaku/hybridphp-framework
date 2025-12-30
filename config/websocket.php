<?php

/**
 * WebSocket Server Configuration
 * 
 * Configuration for the enhanced WebSocket server with room support,
 * heartbeat detection, and reconnection capabilities.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Server Settings
    |--------------------------------------------------------------------------
    */
    'host' => env('WEBSOCKET_HOST', '0.0.0.0'),
    'port' => (int) env('WEBSOCKET_PORT', 2346),
    'processes' => (int) env('WEBSOCKET_PROCESSES', 1),

    /*
    |--------------------------------------------------------------------------
    | SSL/TLS Configuration
    |--------------------------------------------------------------------------
    | Set to null for non-SSL connections, or provide SSL context options
    */
    'ssl' => env('WEBSOCKET_SSL_ENABLED', false) ? [
        'local_cert' => env('WEBSOCKET_SSL_CERT', storage_path('ssl/server.pem')),
        'local_pk' => env('WEBSOCKET_SSL_KEY', storage_path('ssl/server.key')),
        'verify_peer' => false,
    ] : null,

    /*
    |--------------------------------------------------------------------------
    | Heartbeat Configuration
    |--------------------------------------------------------------------------
    | Heartbeat keeps connections alive and detects dead connections
    */
    'heartbeat' => [
        'enabled' => (bool) env('WEBSOCKET_HEARTBEAT_ENABLED', true),
        'interval' => (int) env('WEBSOCKET_HEARTBEAT_INTERVAL', 30), // seconds
        'timeout' => (int) env('WEBSOCKET_HEARTBEAT_TIMEOUT', 60),   // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconnection Configuration
    |--------------------------------------------------------------------------
    | Allows clients to reconnect and restore their session
    */
    'reconnection' => [
        'enabled' => (bool) env('WEBSOCKET_RECONNECTION_ENABLED', true),
        'session_ttl' => (int) env('WEBSOCKET_RECONNECTION_TTL', 300),        // seconds
        'max_attempts' => (int) env('WEBSOCKET_RECONNECTION_MAX_ATTEMPTS', 5),
        'cleanup_interval' => (int) env('WEBSOCKET_RECONNECTION_CLEANUP', 60), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Room/Channel Configuration
    |--------------------------------------------------------------------------
    | Settings for room-based messaging
    */
    'rooms' => [
        'max_connections_per_room' => (int) env('WEBSOCKET_MAX_CONNECTIONS_PER_ROOM', 0), // 0 = unlimited
        'max_rooms_per_connection' => (int) env('WEBSOCKET_MAX_ROOMS_PER_CONNECTION', 0), // 0 = unlimited
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcasting Configuration
    |--------------------------------------------------------------------------
    */
    'broadcasting' => [
        'batch_size' => (int) env('WEBSOCKET_BROADCAST_BATCH_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Message Handlers
    |--------------------------------------------------------------------------
    | Register custom message type handlers
    | Format: 'message_type' => HandlerClass::class or callable
    */
    'handlers' => [
        // 'chat' => \App\WebSocket\Handlers\ChatHandler::class,
        // 'notification' => \App\WebSocket\Handlers\NotificationHandler::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    | Middleware to run on WebSocket connections/messages
    */
    'middleware' => [
        // \App\WebSocket\Middleware\AuthMiddleware::class,
        // \App\WebSocket\Middleware\RateLimitMiddleware::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    | Enable/disable specific WebSocket events
    */
    'events' => [
        'connect' => true,
        'disconnect' => true,
        'message' => true,
        'error' => true,
        'room_join' => true,
        'room_leave' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => (bool) env('WEBSOCKET_LOGGING_ENABLED', true),
        'level' => env('WEBSOCKET_LOG_LEVEL', 'info'),
        'channel' => env('WEBSOCKET_LOG_CHANNEL', 'websocket'),
    ],
];
