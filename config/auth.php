<?php

return [
    // Default authentication guard
    'default' => env('AUTH_GUARD', 'jwt'),
    
    // Authentication guards
    'guards' => [
        'jwt' => [
            'driver' => 'jwt',
            'provider' => 'users',
            'secret' => env('JWT_SECRET', 'your-secret-key'),
            'algorithm' => env('JWT_ALGORITHM', 'HS256'),
            'ttl' => env('JWT_TTL', 3600), // 1 hour
            'refresh_ttl' => env('JWT_REFRESH_TTL', 86400), // 24 hours
        ],
        'session' => [
            'driver' => 'session',
            'provider' => 'users',
            'session_name' => env('SESSION_NAME', 'PHPSESSID'),
            'lifetime' => env('SESSION_LIFETIME', 7200), // 2 hours
        ],
        'oauth2' => [
            'driver' => 'oauth2',
            'provider' => 'users',
            'client_id' => env('OAUTH2_CLIENT_ID'),
            'client_secret' => env('OAUTH2_CLIENT_SECRET'),
            'redirect_uri' => env('OAUTH2_REDIRECT_URI'),
            'scope' => env('OAUTH2_SCOPE', 'read write'),
        ],
    ],
    
    // User providers
    'providers' => [
        'users' => [
            'driver' => 'database',
            'model' => \App\Models\User::class,
            'table' => 'users',
        ],
    ],
    
    // Multi-factor authentication
    'mfa' => [
        'enabled' => env('MFA_ENABLED', false),
        'methods' => [
            'totp' => [
                'enabled' => true,
                'issuer' => env('MFA_TOTP_ISSUER', 'HybridPHP'),
                'digits' => 6,
                'period' => 30,
            ],
            'sms' => [
                'enabled' => env('MFA_SMS_ENABLED', false),
                'provider' => env('MFA_SMS_PROVIDER', 'twilio'),
            ],
            'email' => [
                'enabled' => env('MFA_EMAIL_ENABLED', true),
                'code_length' => 6,
                'ttl' => 300, // 5 minutes
            ],
        ],
    ],
    
    // Password settings
    'passwords' => [
        'min_length' => env('PASSWORD_MIN_LENGTH', 8),
        'require_uppercase' => env('PASSWORD_REQUIRE_UPPERCASE', true),
        'require_lowercase' => env('PASSWORD_REQUIRE_LOWERCASE', true),
        'require_numbers' => env('PASSWORD_REQUIRE_NUMBERS', true),
        'require_symbols' => env('PASSWORD_REQUIRE_SYMBOLS', false),
        'hash_algorithm' => PASSWORD_DEFAULT,
    ],
    
    // RBAC settings
    'rbac' => [
        'enabled' => env('RBAC_ENABLED', true),
        'cache_permissions' => env('RBAC_CACHE_PERMISSIONS', true),
        'cache_ttl' => env('RBAC_CACHE_TTL', 3600),
    ],
    
    // Security settings
    'security' => [
        'max_login_attempts' => env('AUTH_MAX_LOGIN_ATTEMPTS', 5),
        'lockout_duration' => env('AUTH_LOCKOUT_DURATION', 900), // 15 minutes
        'password_reset_ttl' => env('PASSWORD_RESET_TTL', 3600), // 1 hour
        'remember_me_ttl' => env('REMEMBER_ME_TTL', 2592000), // 30 days
    ],
];