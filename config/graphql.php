<?php

/**
 * GraphQL Configuration
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Enable GraphQL
    |--------------------------------------------------------------------------
    |
    | Enable or disable the GraphQL endpoint.
    |
    */
    'enabled' => env('GRAPHQL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | GraphQL Endpoint
    |--------------------------------------------------------------------------
    |
    | The URL path for the GraphQL endpoint.
    |
    */
    'endpoint' => env('GRAPHQL_ENDPOINT', '/graphql'),

    /*
    |--------------------------------------------------------------------------
    | Subscriptions Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for GraphQL subscriptions over WebSocket.
    |
    */
    'subscriptions' => [
        'enabled' => env('GRAPHQL_SUBSCRIPTIONS_ENABLED', true),
        'endpoint' => env('GRAPHQL_SUBSCRIPTIONS_ENDPOINT', '/graphql/subscriptions'),
        'keepAlive' => 30000, // milliseconds
        'connectionTimeout' => 5000, // milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Introspection
    |--------------------------------------------------------------------------
    |
    | Enable or disable GraphQL introspection queries.
    | Should be disabled in production for security.
    |
    */
    'introspection' => env('GRAPHQL_INTROSPECTION', true),

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | Enable debug mode to include detailed error information.
    | Should be disabled in production.
    |
    */
    'debug' => env('GRAPHQL_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Query Limits
    |--------------------------------------------------------------------------
    |
    | Limits to prevent abuse and DoS attacks.
    |
    */
    'limits' => [
        'maxBatchSize' => 10,
        'maxQueryDepth' => 15,
        'maxQueryComplexity' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema Configuration
    |--------------------------------------------------------------------------
    |
    | Path to schema definition files or classes.
    |
    */
    'schema' => [
        // Schema class or file path
        'default' => null,
        
        // Additional type definitions
        'types' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware to apply to GraphQL requests.
    |
    */
    'middleware' => [
        // 'auth',
        // 'throttle:60,1',
    ],

    /*
    |--------------------------------------------------------------------------
    | DataLoader Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for DataLoader batch loading.
    |
    */
    'dataloader' => [
        'cache' => true,
        'maxBatchSize' => 100,
    ],
];
