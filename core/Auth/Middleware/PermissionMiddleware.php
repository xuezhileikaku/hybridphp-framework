<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth\Middleware;

use Amp\Future;
use HybridPHP\Core\MiddlewareInterface;
use HybridPHP\Core\Auth\RBAC\RBACManager;
use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use function Amp\async;

/**
 * Permission-based authorization middleware
 */
class PermissionMiddleware implements MiddlewareInterface
{
    private RBACManager $rbacManager;
    private string $permission;
    private ?string $resource;

    public function __construct(RBACManager $rbacManager, string $permission, ?string $resource = null)
    {
        $this->rbacManager = $rbacManager;
        $this->permission = $permission;
        $this->resource = $resource;
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
            $user = $request->getAttribute('user');
            
            if (!$user) {
                return $this->unauthorizedResponse($request);
            }

            $hasPermission = $this->rbacManager->hasPermission($user, $this->permission, $this->resource)->await();
            
            if (!$hasPermission) {
                return $this->forbiddenResponse($request);
            }

            return $next($request)->await();
        });
    }

    /**
     * Return unauthorized response
     *
     * @param Request $request
     * @return Response
     */
    private function unauthorizedResponse(Request $request): Response
    {
        if ($this->isApiRequest($request)) {
            return new Response(401, [], json_encode([
                'error' => 'Unauthorized',
                'message' => 'Authentication required'
            ]));
        }

        return new Response(302, ['Location' => '/login']);
    }

    /**
     * Return forbidden response
     *
     * @param Request $request
     * @return Response
     */
    private function forbiddenResponse(Request $request): Response
    {
        if ($this->isApiRequest($request)) {
            return new Response(403, [], json_encode([
                'error' => 'Forbidden',
                'message' => 'Insufficient permissions'
            ]));
        }

        return new Response(403, [], 'Access Denied');
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