<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth\Middleware;

use Amp\Future;
use HybridPHP\Core\MiddlewareInterface;
use HybridPHP\Core\Auth\AuthManager;
use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use function Amp\async;

/**
 * Authentication middleware
 */
class AuthMiddleware implements MiddlewareInterface
{
    private AuthManager $authManager;
    private array $config;

    public function __construct(AuthManager $authManager, array $config = [])
    {
        $this->authManager = $authManager;
        $this->config = array_merge([
            'guard' => null,
            'redirect_to' => '/login',
            'header_name' => 'Authorization',
            'token_prefix' => 'Bearer ',
        ], $config);
    }

    /**
     * Process the middleware
     *
     * @param Request $request
     * @param callable $next
     * @return Future<Response>
     */
    public function process(Request $request, callable $next): Future
    {
        return async(function () use ($request, $next) {
            $token = $this->extractToken($request);
            
            if ($token) {
                $user = $this->authManager->validateToken($token, $this->config['guard'])->await();
                if ($user) {
                    // Set authenticated user in request
                    $request->setAttribute('user', $user);
                    $request->setAttribute('authenticated', true);
                    
                    return $next($request)->await();
                }
            }

            // Check if user is already authenticated via session
            $isAuthenticated = $this->authManager->check($this->config['guard'])->await();
            if ($isAuthenticated) {
                $user = $this->authManager->user($this->config['guard'])->await();
                $request->setAttribute('user', $user);
                $request->setAttribute('authenticated', true);
                
                return $next($request)->await();
            }

            // User is not authenticated
            $request->setAttribute('authenticated', false);
            
            // For API requests, return 401
            if ($this->isApiRequest($request)) {
                return new Response(401, [], json_encode([
                    'error' => 'Unauthorized',
                    'message' => 'Authentication required'
                ]));
            }

            // For web requests, redirect to login
            return new Response(302, [
                'Location' => $this->config['redirect_to']
            ]);
        });
    }

    /**
     * Extract token from request
     *
     * @param Request $request
     * @return string|null
     */
    private function extractToken(Request $request): ?string
    {
        // Check Authorization header
        $authHeader = $request->getHeader($this->config['header_name']);
        if ($authHeader && str_starts_with($authHeader, $this->config['token_prefix'])) {
            return substr($authHeader, strlen($this->config['token_prefix']));
        }

        // Check query parameter
        $token = $request->getQueryParam('token');
        if ($token) {
            return $token;
        }

        // Check cookie
        $cookieToken = $request->getCookie('auth_token');
        if ($cookieToken) {
            return $cookieToken;
        }

        return null;
    }

    /**
     * Check if request is API request
     *
     * @param Request $request
     * @return bool
     */
    private function isApiRequest(Request $request): bool
    {
        $acceptHeader = $request->getHeader('Accept');
        $contentType = $request->getHeader('Content-Type');
        
        return str_contains($acceptHeader, 'application/json') ||
               str_contains($contentType, 'application/json') ||
               str_starts_with($request->getPath(), '/api/');
    }
}