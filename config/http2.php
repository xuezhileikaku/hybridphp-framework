<?php

declare(strict_types=1);

/**
 * HTTP/2 Server Configuration
 * 
 * Configuration for HTTP/2 server with TLS, server push,
 * multiplexing, and HPACK header compression.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | HTTP/2 Server Settings
    |--------------------------------------------------------------------------
    |
    | Basic server configuration for HTTP/2 connections.
    |
    */

    'server' => [
        // Server host
        'host' => env('HTTP2_HOST', '0.0.0.0'),
        
        // Server port (default 8443 for HTTPS/HTTP2)
        'port' => (int) env('HTTP2_PORT', 8443),
        
        // Enable HTTP/2 protocol
        'enable_http2' => (bool) env('HTTP2_ENABLED', true),
        
        // Connection timeout in seconds
        'connection_timeout' => (int) env('HTTP2_CONNECTION_TIMEOUT', 30),
        
        // Request timeout in seconds
        'request_timeout' => (int) env('HTTP2_REQUEST_TIMEOUT', 30),
        
        // Maximum request body size (128MB default)
        'body_size_limit' => (int) env('HTTP2_BODY_SIZE_LIMIT', 128 * 1024 * 1024),
        
        // Enable response compression
        'enable_compression' => (bool) env('HTTP2_COMPRESSION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP/2 Protocol Settings
    |--------------------------------------------------------------------------
    |
    | HTTP/2 specific protocol settings for streams and flow control.
    |
    */

    'protocol' => [
        // Maximum concurrent streams per connection
        'max_concurrent_streams' => (int) env('HTTP2_MAX_STREAMS', 100),
        
        // Initial window size for flow control (bytes)
        'initial_window_size' => (int) env('HTTP2_INITIAL_WINDOW_SIZE', 65535),
        
        // Maximum frame size (bytes, 16KB-16MB)
        'max_frame_size' => (int) env('HTTP2_MAX_FRAME_SIZE', 16384),
        
        // Maximum header list size (bytes)
        'max_header_list_size' => (int) env('HTTP2_MAX_HEADER_LIST_SIZE', 8192),
        
        // HPACK header table size (bytes)
        'header_table_size' => (int) env('HTTP2_HEADER_TABLE_SIZE', 4096),
    ],

    /*
    |--------------------------------------------------------------------------
    | TLS/SSL Configuration
    |--------------------------------------------------------------------------
    |
    | TLS settings required for HTTP/2 (ALPN negotiation).
    |
    */

    'tls' => [
        // SSL certificate path
        'cert_path' => env('TLS_CERT_PATH', 'storage/ssl/server.crt'),
        
        // SSL private key path
        'key_path' => env('TLS_KEY_PATH', 'storage/ssl/server.key'),
        
        // CA certificate path (optional)
        'ca_path' => env('TLS_CA_PATH', null),
        
        // Minimum TLS version (TLSv1.2 required for HTTP/2)
        'min_version' => env('TLS_MIN_VERSION', 'TLSv1.2'),
        
        // Maximum TLS version
        'max_version' => env('TLS_MAX_VERSION', 'TLSv1.3'),
        
        // Verify peer certificate
        'verify_peer' => (bool) env('TLS_VERIFY_PEER', true),
        
        // Allow self-signed certificates (development only)
        'allow_self_signed' => (bool) env('TLS_ALLOW_SELF_SIGNED', false),
        
        // HTTP/2 compatible cipher suites
        'cipher_suites' => [
            'ECDHE-ECDSA-AES256-GCM-SHA384',
            'ECDHE-RSA-AES256-GCM-SHA384',
            'ECDHE-ECDSA-CHACHA20-POLY1305',
            'ECDHE-RSA-CHACHA20-POLY1305',
            'ECDHE-ECDSA-AES128-GCM-SHA256',
            'ECDHE-RSA-AES128-GCM-SHA256',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Push Configuration
    |--------------------------------------------------------------------------
    |
    | HTTP/2 Server Push settings for proactive resource delivery.
    |
    */

    'server_push' => [
        // Enable server push
        'enabled' => (bool) env('HTTP2_SERVER_PUSH', true),
        
        // Maximum resources to push per request
        'max_push_resources' => (int) env('HTTP2_MAX_PUSH_RESOURCES', 10),
        
        // Auto-detect resources from HTML responses
        'auto_detect' => true,
        
        // Push rules: URL pattern => resources to push
        'rules' => [
            // Example: Push CSS and JS for homepage
            // '/' => [
            //     '/css/app.css' => 'style',
            //     '/js/app.js' => 'script',
            // ],
            
            // Example: Push common assets for all pages
            // '/*' => [
            //     '/css/common.css' => 'style',
            //     '/js/common.js' => 'script',
            // ],
        ],
        
        // Pre-registered resources for server push
        'resources' => [
            // '/css/critical.css' => [
            //     'type' => 'style',
            //     'priority' => 'high',
            // ],
            // '/fonts/main.woff2' => [
            //     'type' => 'font',
            //     'crossorigin' => 'anonymous',
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Headers
    |--------------------------------------------------------------------------
    |
    | Security headers automatically added to HTTP/2 responses.
    |
    */

    'security_headers' => [
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Performance optimization settings for HTTP/2.
    |
    */

    'performance' => [
        // Enable header compression statistics
        'track_compression' => (bool) env('HTTP2_TRACK_COMPRESSION', false),
        
        // Enable stream statistics
        'track_streams' => (bool) env('HTTP2_TRACK_STREAMS', false),
        
        // Connection keep-alive timeout (seconds)
        'keep_alive_timeout' => (int) env('HTTP2_KEEP_ALIVE_TIMEOUT', 60),
        
        // Ping interval for connection health (seconds, 0 to disable)
        'ping_interval' => (int) env('HTTP2_PING_INTERVAL', 30),
    ],
];
