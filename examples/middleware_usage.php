<?php
/**
 * Example: Using the Async Middleware System
 * 
 * This example demonstrates how to use the PSR-15 compatible async middleware system
 * with global, group, and route-specific middleware.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HybridPHP\Core\MiddlewareManager;
use HybridPHP\Core\MiddlewarePipeline;
use HybridPHP\Core\Middleware\CorsMiddleware;
use HybridPHP\Core\Middleware\AuthMiddleware;
use HybridPHP\Core\Middleware\LoggingMiddleware;
use HybridPHP\Core\Middleware\RateLimitMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Amp\Http\Server\Response;
// Example core handler (your actual application logic)
class ExampleHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        
        return new Response(200, [
            'Content-Type' => 'application/json'
        ], json_encode([
            'message' => 'Hello from HybridPHP!',
            'path' => $path,
            'method' => $method,
            'user' => $request->getAttribute('user'),
            'request_id' => $request->getAttribute('request_id'),
        ]));
    }
}

// Example usage
function demonstrateMiddleware()
{
    // Create middleware manager
    $middlewareManager = new MiddlewareManager();
    
    // Add global middleware (applied to all requests)
    $middlewareManager->addGlobal('cors', 100); // High priority
    $middlewareManager->addGlobal('log', 90);   // Lower priority
    
    // Add middleware to groups
    $middlewareManager->addToGroup('api', 'throttle', 80);
    $middlewareManager->addToGroup('api', 'auth', 70);
    
    // Add route-specific middleware
    $middlewareManager->addToRoute('user.profile', 'auth', 60);
    
    // Create core handler
    $coreHandler = new ExampleHandler();
    
    // Example 1: Public endpoint (only global middleware)
    echo "=== Public Endpoint Pipeline ===\n";
    $publicPipeline = $middlewareManager->createPipeline($coreHandler);
    $publicMiddleware = $publicPipeline->getMiddleware();
    echo "Middleware count: " . count($publicMiddleware) . "\n";
    foreach ($publicMiddleware as $middleware) {
        echo "- " . (is_string($middleware) ? $middleware : get_class($middleware)) . "\n";
    }
    
    // Example 2: API endpoint (global + api group middleware)
    echo "\n=== API Endpoint Pipeline ===\n";
    $apiPipeline = $middlewareManager->createPipeline($coreHandler, ['api']);
    $apiMiddleware = $apiPipeline->getMiddleware();
    echo "Middleware count: " . count($apiMiddleware) . "\n";
    foreach ($apiMiddleware as $middleware) {
        echo "- " . (is_string($middleware) ? $middleware : get_class($middleware)) . "\n";
    }
    
    // Example 3: Protected route (global + api group + route-specific)
    echo "\n=== Protected Route Pipeline ===\n";
    $protectedPipeline = $middlewareManager->createPipeline(
        $coreHandler, 
        ['api'], 
        'user.profile'
    );
    $protectedMiddleware = $protectedPipeline->getMiddleware();
    echo "Middleware count: " . count($protectedMiddleware) . "\n";
    foreach ($protectedMiddleware as $middleware) {
        echo "- " . (is_string($middleware) ? $middleware : get_class($middleware)) . "\n";
    }
    
    // Example 4: Custom middleware configuration
    echo "\n=== Custom Middleware Configuration ===\n";
    
    // Create middleware with custom configuration
    $corsMiddleware = new CorsMiddleware([
        'allowed_origins' => ['https://example.com', 'https://app.example.com'],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
        'credentials' => true,
    ]);
    
    $rateLimitMiddleware = new RateLimitMiddleware(null, [
        'algorithm' => 'token_bucket',
        'max_requests' => 60,
        'window_seconds' => 60,
        'burst_size' => 10,
    ]);
    
    // Create pipeline with custom middleware instances
    $customPipeline = new MiddlewarePipeline($coreHandler);
    $customPipeline->through([
        $corsMiddleware,
        $rateLimitMiddleware,
        AuthMiddleware::class, // Can mix instances and class names
    ]);
    
    echo "Custom pipeline created with " . count($customPipeline->getMiddleware()) . " middleware\n";
    
    // Example 5: Middleware aliases
    echo "\n=== Middleware Aliases ===\n";
    $middlewareManager->alias('custom_auth', AuthMiddleware::class);
    $middlewareManager->alias('api_limit', RateLimitMiddleware::class);
    
    $aliasedPipeline = $middlewareManager->createPipeline($coreHandler);
    $aliasedPipeline->through(['custom_auth', 'api_limit']);
    
    echo "Pipeline with aliased middleware created\n";
    
    echo "\n=== Middleware System Ready ===\n";
    echo "The async middleware system is now configured and ready to use!\n";
    echo "All middleware is PSR-15 compatible and coroutine-safe.\n";
}

// Run the demonstration
demonstrateMiddleware();