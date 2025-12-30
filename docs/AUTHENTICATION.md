# HybridPHP Authentication & Authorization System

The HybridPHP framework provides a comprehensive authentication and authorization system with multi-factor authentication (MFA) support, role-based access control (RBAC), and multiple authentication guards.

## Features

- **Multiple Authentication Guards**: JWT, Session, OAuth2
- **Role-Based Access Control (RBAC)**: Flexible permission system
- **Multi-Factor Authentication (MFA)**: TOTP, Email, SMS support
- **Password Security**: Configurable password policies
- **Session Management**: Secure session handling
- **Middleware Integration**: Easy route protection
- **Async/Await Support**: Full async operation support

## Quick Start

### 1. Configuration

Configure authentication in `config/auth.php`:

```php
return [
    'default' => 'jwt',
    'guards' => [
        'jwt' => [
            'driver' => 'jwt',
            'provider' => 'users',
            'secret' => env('JWT_SECRET', 'your-secret-key'),
            'ttl' => 3600, // 1 hour
        ],
        'session' => [
            'driver' => 'session',
            'provider' => 'users',
            'lifetime' => 7200, // 2 hours
        ],
    ],
    'providers' => [
        'users' => [
            'driver' => 'database',
            'model' => \App\Models\User::class,
            'table' => 'users',
        ],
    ],
    'mfa' => [
        'enabled' => true,
        'methods' => [
            'totp' => ['enabled' => true],
            'email' => ['enabled' => true],
        ],
    ],
    'rbac' => [
        'enabled' => true,
        'cache_permissions' => true,
    ],
];
```

### 2. Database Migration

Run the authentication tables migration:

```bash
php bin/hybrid migrate:run
```

### 3. Service Registration

Register authentication services in your application bootstrap:

```php
use HybridPHP\Core\Auth\AuthServiceProvider;

$authProvider = new AuthServiceProvider($container);
$authProvider->register();
$authProvider->boot();
```

## Authentication Guards

### JWT Guard

Perfect for API authentication:

```php
use function HybridPHP\Core\Auth\auth;

// Authenticate user
$user = auth()->guard('jwt')->attempt([
    'username' => 'john@example.com',
    'password' => 'password123'
])->await();

// Generate token
$token = auth()->guard('jwt')->login($user)->await();

// Validate token
$user = auth()->guard('jwt')->validateToken($token)->await();
```

### Session Guard

For traditional web applications:

```php
// Login with session
$user = auth()->guard('session')->attempt($credentials)->await();
$sessionId = auth()->guard('session')->login($user)->await();

// Check authentication
$isAuthenticated = auth()->guard('session')->check()->await();
```

### OAuth2 Guard

For third-party authentication:

```php
// Get authorization URL
$authUrl = auth()->guard('oauth2')->getAuthorizationUrl();

// Exchange code for token
$tokenData = auth()->guard('oauth2')->exchangeCodeForToken($code)->await();

// Validate access token
$user = auth()->guard('oauth2')->validateToken($accessToken)->await();
```

## User Component

The User component provides a Yii2-style interface:

```php
use function HybridPHP\Core\Auth\user;

// Login
$success = user()->loginByUsernameAndPassword('john', 'password123')->await();

// Get current user
$identity = user()->getIdentity()->await();

// Check if guest
$isGuest = user()->getIsGuest()->await();

// Check permissions
$canEdit = user()->can('posts.edit')->await();

// Check roles
$isAdmin = user()->hasRole('admin')->await();

// Logout
user()->logout()->await();
```

## Role-Based Access Control (RBAC)

### Creating Roles and Permissions

```php
use function HybridPHP\Core\Auth\rbac;

// Create permissions
yield rbac()->createPermission('posts.read', 'Read posts');
yield rbac()->createPermission('posts.write', 'Write posts');
yield rbac()->createPermission('posts.delete', 'Delete posts');

// Create roles
yield rbac()->createRole('editor', 'Content Editor', [
    'posts.read', 'posts.write'
]);
yield rbac()->createRole('admin', 'Administrator', [
    'posts.read', 'posts.write', 'posts.delete'
]);
```

### Assigning Roles and Permissions

```php
// Assign role to user
yield rbac()->assignRole($user, 'editor');

// Grant direct permission
yield rbac()->grantPermission($user, 'posts.delete');

// Check permissions
$hasPermission = yield rbac()->hasPermission($user, 'posts.write');
$hasRole = yield rbac()->hasRole($user, 'admin');
```

## Multi-Factor Authentication (MFA)

### TOTP (Time-based One-Time Password)

```php
use function HybridPHP\Core\Auth\mfa;

// Generate TOTP secret
$secret = yield mfa()->generateSecret($user, 'totp');

// Get QR code URL for setup
$qrCodeUrl = mfa()->getQRCodeUrl($user, $secret);

// Enable TOTP
yield mfa()->enableMethod($user, 'totp', $secret);

// Verify TOTP code
$isValid = yield mfa()->verifyCode($user, '123456', 'totp');
```

### Email MFA

```php
// Enable email MFA
yield mfa()->enableMethod($user, 'email', '');

// Send verification code
yield mfa()->sendCode($user, 'email');

// Verify code
$isValid = yield mfa()->verifyCode($user, '123456', 'email');
```

### Backup Codes

```php
// Generate backup codes
$backupCodes = yield mfa()->generateBackupCodes($user);

// Verify backup code
$isValid = yield mfa()->verifyBackupCode($user, 'ABCD-1234');
```

## Middleware

### Authentication Middleware

Protect routes with authentication:

```php
use HybridPHP\Core\Auth\Middleware\AuthMiddleware;

Router::group(['middleware' => [AuthMiddleware::class]], function () {
    Router::get('/dashboard', [DashboardController::class, 'index']);
});
```

### Permission Middleware

Require specific permissions:

```php
use HybridPHP\Core\Auth\Middleware\PermissionMiddleware;

Router::group([
    'middleware' => [new PermissionMiddleware(rbac(), 'posts.write')]
], function () {
    Router::post('/posts', [PostController::class, 'create']);
});
```

### Role Middleware

Require specific roles:

```php
use HybridPHP\Core\Auth\Middleware\RoleMiddleware;

Router::group([
    'middleware' => [new RoleMiddleware(rbac(), ['admin', 'moderator'])]
], function () {
    Router::get('/admin', [AdminController::class, 'index']);
});
```

### MFA Middleware

Require MFA verification:

```php
use HybridPHP\Core\Auth\Middleware\MFAMiddleware;

Router::group([
    'middleware' => [AuthMiddleware::class, MFAMiddleware::class]
], function () {
    Router::get('/secure', [SecureController::class, 'index']);
});
```

## Helper Functions

The framework provides convenient helper functions:

```php
// Authentication
$authManager = auth();
$userComponent = user();
$rbacManager = rbac();
$mfaManager = mfa();

// User checks
$canEdit = yield can('posts.edit');
$isAdmin = yield hasRole('admin');
$isGuest = yield isGuest();
$currentUser = yield currentUser();

// JWT utilities
$token = generateJWT($user);
$payload = parseJWT($token);

// Password utilities
$hash = hashPassword('password123');
$isValid = verifyPassword('password123', $hash);
$errors = validatePassword('weak'); // Returns validation errors
```

## Controllers

### AuthController

Handles authentication operations:

```php
// Login
POST /auth/login
{
    "username": "john@example.com",
    "password": "password123",
    "remember": true
}

// MFA verification
POST /auth/mfa/verify
{
    "code": "123456",
    "method": "totp"
}

// Enable MFA
POST /auth/mfa/enable
{
    "method": "totp"
}
```

### UserController

Manages users and permissions:

```php
// Assign role
POST /admin/users/1/roles
{
    "role": "admin"
}

// Grant permission
POST /admin/users/1/permissions
{
    "permission": "posts.write"
}
```

## Security Features

### Password Policies

Configure password requirements:

```php
'passwords' => [
    'min_length' => 8,
    'require_uppercase' => true,
    'require_lowercase' => true,
    'require_numbers' => true,
    'require_symbols' => false,
],
```

### Login Attempt Limiting

Track and limit failed login attempts:

```php
'security' => [
    'max_login_attempts' => 5,
    'lockout_duration' => 900, // 15 minutes
],
```

### Token Security

JWT tokens include:
- Expiration time
- User identification
- Role information
- Issuer validation

## Testing

Run the authentication system test:

```bash
php test_auth_system.php
```

This will test:
- User creation and authentication
- Role and permission assignment
- JWT token generation and validation
- MFA functionality
- RBAC operations

## Best Practices

1. **Use HTTPS**: Always use HTTPS in production
2. **Strong Secrets**: Use strong, random JWT secrets
3. **Token Expiration**: Set appropriate token expiration times
4. **MFA for Admins**: Require MFA for administrative accounts
5. **Permission Caching**: Enable permission caching for better performance
6. **Regular Audits**: Regularly audit user permissions and roles
7. **Secure Storage**: Store sensitive data securely
8. **Rate Limiting**: Implement rate limiting for authentication endpoints

## API Examples

### Authentication Flow

```javascript
// 1. Login
const loginResponse = await fetch('/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        username: 'john@example.com',
        password: 'password123'
    })
});

const loginData = await loginResponse.json();

if (loginData.mfa_required) {
    // 2. MFA verification required
    const mfaResponse = await fetch('/auth/mfa/verify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            code: '123456',
            method: 'totp'
        })
    });
}

// 3. Use JWT token for API requests
const apiResponse = await fetch('/api/v1/users', {
    headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
    }
});
```

## Troubleshooting

### Common Issues

1. **JWT Secret Not Set**: Ensure `JWT_SECRET` is configured
2. **Database Tables Missing**: Run migrations
3. **Permission Denied**: Check user roles and permissions
4. **MFA Not Working**: Verify MFA configuration and time sync
5. **Session Issues**: Check cache configuration

### Debug Mode

Enable debug logging in your configuration:

```php
'auth' => [
    'debug' => env('AUTH_DEBUG', false),
    // ... other config
],
```

This comprehensive authentication system provides enterprise-grade security features while maintaining ease of use and flexibility for various application types.