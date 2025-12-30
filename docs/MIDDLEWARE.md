# HybridPHP Async Middleware System

## Overview

The HybridPHP framework features a comprehensive async middleware system that implements the PSR-15 standard with full coroutine compatibility. The middleware system supports the onion model pattern and provides global, group, and route-specific middleware management.

## Key Features

- **PSR-15 Compliant**: Full compatibility with PSR-15 middleware standard
- **Async/Coroutine Compatible**: All middleware runs in async context without blocking
- **Onion Model**: Proper request/response flow through middleware layers
- **Flexible Organization**: Global, group, and route-specific middleware support
- **Priority System**: Control middleware execution order with priorities
- **Built-in Middleware**: Authentication, CORS, logging, and rate limiting included
- **Extensible**: Easy to create custom middleware

## Architecture

### Core Components

1. **MiddlewareManager**: Manages middleware registration and organization
2. **MiddlewarePipeline**: Executes middleware in onion model pattern
3. **MiddlewareInterface**: Extends PSR-15 with async compatibility
4. **AbstractMiddleware**: Base class for easy middleware creation

### Built-in Middleware

1. **AuthMiddleware**: JWT, session, and API key authentication
2. **CorsMiddleware**: Cross-Origin Resource Sharing headers
3. **LoggingMiddleware**: Structured async request/response logging
4. **RateLimitMiddleware**: Token bucket and sliding window rate limiting

## Usage Examples

### Basic Setup

```php
use HybridPHP\Core\MiddlewareManager;
use HybridPHP\Core\MiddlewarePipeline;

// Create middleware manager
$manager = new MiddlewareManager();

// Add global middleware (applied to all requests)
$manager->addGlobal('cors', 100);  // High priority
$manager->addGlobal('log', 90);    // Lower priority

// Add group middleware
$manager->addToGroup('api', 'throttle', 80);
$manager->addToGroup('api', 'auth', 70);

// Add route-specific middleware
$manager->addToRoute('user.profile', 'auth', 60);
```

### Creating Pipelines

```php
// Create pipeline for different scenarios
$publicPipeline = $manager->createPipeline($handler);
$apiPipeline = $manager->createPipeline($handler, ['api']);
$protectedPipeline = $manager->createPipeline($handler, ['api'], 'user.profile');

// Process request through pipeline
$response = $pipeline->handle($request);
```

### Custom Middleware

```php
use HybridPHP\Core\Middleware\AbstractMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

class CustomMiddleware extends AbstractMiddleware
{
    protected function before(ServerRequestInterface $request): ServerRequestInterface
    {
        // Pre-processing logic
        return $request->withAttribute('custom_data', 'value');
    }
    
    protected function after(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Post-processing logic
        return $response->withHeader('X-Custom-Header', 'processed');
    }
}
```

## Built-in Middleware Configuration

### CORS Middleware

```php
$corsMiddleware = new CorsMiddleware([
    'allowed_origins' => ['https://example.com', 'https://app.example.com'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
    'allowed_headers' => ['Content-Type', 'Authorization'],
    'credentials' => true,
    'max_age' => 86400
]);
```

### Authentication Middleware

```php
$authMiddleware = new AuthMiddleware([
    'auth_type' => 'jwt',  // jwt, session, api_key
    'jwt_secret' => 'your-secret-key',
    'excluded_paths' => ['/login', '/register', '/health'],
    'unauthorized_message' => 'Access denied'
]);
```

### Rate Limiting Middleware

```php
$rateLimitMiddleware = new RateLimitMiddleware(null, [
    'algorithm' => 'token_bucket',  // token_bucket, sliding_window, fixed_window
    'max_requests' => 100,
    'window_seconds' => 3600,
    'burst_size' => 10,
    'refill_rate' => 1
]);
```

### Logging Middleware

```php
$loggingMiddleware = new LoggingMiddleware($logger, [
    'log_requests' => true,
    'log_responses' => true,
    'log_request_body' => false,
    'excluded_paths' => ['/health', '/metrics'],
    'max_body_size' => 1024
]);
```

## Middleware Aliases

The system supports middleware aliases for cleaner configuration:

```php
// Built-in aliases
$manager->addGlobal('cors');     // HybridPHP\Core\Middleware\CorsMiddleware
$manager->addGlobal('auth');     // HybridPHP\Core\Middleware\AuthMiddleware
$manager->addGlobal('log');      // HybridPHP\Core\Middleware\LoggingMiddleware
$manager->addGlobal('throttle'); // HybridPHP\Core\Middleware\RateLimitMiddleware

// Custom aliases
$manager->alias('custom_auth', CustomAuthMiddleware::class);
$manager->addGlobal('custom_auth');
```

## Priority System

Middleware execution order is controlled by priority values (higher = earlier execution):

```php
$manager->addGlobal('cors', 100);    // Executes first
$manager->addGlobal('auth', 75);     // Executes second
$manager->addGlobal('log', 50);      // Executes third
```

## Async Compatibility

All middleware in HybridPHP is designed to work with AMPHP's fiber-based async operations:

```php
class AsyncMiddleware extends AbstractMiddleware
{
    protected function before(ServerRequestInterface $request): ServerRequestInterface
    {
        // Async operations work seamlessly
        // No need for explicit yield or await in AMPHP v3
        $data = $this->asyncService->fetchData();
        return $request->withAttribute('async_data', $data);
    }
}
```

## Error Handling

Middleware can handle errors and exceptions:

```php
class ErrorHandlingMiddleware extends AbstractMiddleware
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return parent::process($request, $handler);
        } catch (\Throwable $e) {
            // Log error and return error response
            $this->logger->error('Middleware error', ['exception' => $e]);
            return new Response(500, [], 'Internal Server Error');
        }
    }
}
```

## Testing

The middleware system includes comprehensive test coverage:

```bash
# Run unit tests
php vendor/bin/phpunit tests/Unit/MiddlewareSystemTest.php

# Run integration tests
php vendor/bin/phpunit tests/Feature/MiddlewareIntegrationTest.php

# Run basic functionality test
php test_middleware.php

# Run usage examples
php examples/middleware_usage.php
```

## Performance Considerations

- Middleware executes in order of priority
- Use appropriate priorities to optimize execution flow
- Consider middleware overhead when designing complex pipelines
- Rate limiting middleware uses memory storage by default (Redis recommended for production)
- Logging middleware supports async batch writing to avoid blocking

## Best Practices

1. **Keep middleware focused**: Each middleware should have a single responsibility
2. **Use appropriate priorities**: Critical middleware (CORS, security) should have high priority
3. **Handle errors gracefully**: Always provide fallback responses for error conditions
4. **Optimize for async**: Avoid blocking operations in middleware
5. **Test thoroughly**: Use both unit and integration tests for custom middleware
6. **Document configuration**: Provide clear configuration options for custom middleware

## Integration with Routing

Middleware integrates seamlessly with the routing system:

```php
// Route-specific middleware
Route::get('/api/users', UserController::class)
    ->middleware(['auth', 'throttle']);

// Route group middleware
Route::group(['prefix' => 'api', 'middleware' => ['cors', 'auth']], function() {
    Route::get('/users', UserController::class);
    Route::post('/users', UserController::class);
});
```

This middleware system provides a robust, async-compatible foundation for handling HTTP requests in the HybridPHP framework while maintaining full PSR-15 compliance and excellent performance characteristics.