<?php
namespace HybridPHP\Core;

use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware manager for organizing global, group, and route-specific middleware
 */
class MiddlewareManager
{
    private array $globalMiddleware = [];
    private array $groupMiddleware = [];
    private array $routeMiddleware = [];
    private array $middlewareAliases = [];

    public function __construct()
    {
        $this->registerDefaultAliases();
    }

    /**
     * Register default middleware aliases
     */
    private function registerDefaultAliases(): void
    {
        $this->middlewareAliases = [
            'auth' => \HybridPHP\Core\Middleware\AuthMiddleware::class,
            'cors' => \HybridPHP\Core\Middleware\CorsMiddleware::class,
            'log' => \HybridPHP\Core\Middleware\LoggingMiddleware::class,
            'throttle' => \HybridPHP\Core\Middleware\RateLimitMiddleware::class,
            // Security middleware aliases
            'csrf' => \HybridPHP\Core\Middleware\CsrfProtectionMiddleware::class,
            'xss' => \HybridPHP\Core\Middleware\XssProtectionMiddleware::class,
            'sql-injection' => \HybridPHP\Core\Middleware\SqlInjectionProtectionMiddleware::class,
            'input-validation' => \HybridPHP\Core\Middleware\InputValidationMiddleware::class,
            'security-headers' => \HybridPHP\Core\Middleware\SecurityHeadersMiddleware::class,
            'csp' => \HybridPHP\Core\Middleware\ContentSecurityPolicyMiddleware::class,
        ];
    }

    /**
     * Add global middleware (applied to all requests)
     *
     * @param string|MiddlewareInterface $middleware
     * @param int $priority
     * @return self
     */
    public function addGlobal($middleware, int $priority = 0): self
    {
        $this->globalMiddleware[] = [
            'middleware' => $this->resolveMiddleware($middleware),
            'priority' => $priority
        ];

        // Sort by priority (higher priority first)
        usort($this->globalMiddleware, fn($a, $b) => $b['priority'] <=> $a['priority']);

        return $this;
    }

    /**
     * Add middleware to a specific group
     *
     * @param string $group
     * @param string|MiddlewareInterface $middleware
     * @param int $priority
     * @return self
     */
    public function addToGroup(string $group, $middleware, int $priority = 0): self
    {
        if (!isset($this->groupMiddleware[$group])) {
            $this->groupMiddleware[$group] = [];
        }

        $this->groupMiddleware[$group][] = [
            'middleware' => $this->resolveMiddleware($middleware),
            'priority' => $priority
        ];

        // Sort by priority
        usort($this->groupMiddleware[$group], fn($a, $b) => $b['priority'] <=> $a['priority']);

        return $this;
    }

    /**
     * Register a route-specific middleware
     *
     * @param string $routeName
     * @param string|MiddlewareInterface $middleware
     * @param int $priority
     * @return self
     */
    public function addToRoute(string $routeName, $middleware, int $priority = 0): self
    {
        if (!isset($this->routeMiddleware[$routeName])) {
            $this->routeMiddleware[$routeName] = [];
        }

        $this->routeMiddleware[$routeName][] = [
            'middleware' => $this->resolveMiddleware($middleware),
            'priority' => $priority
        ];

        // Sort by priority
        usort($this->routeMiddleware[$routeName], fn($a, $b) => $b['priority'] <=> $a['priority']);

        return $this;
    }

    /**
     * Register a middleware alias
     *
     * @param string $alias
     * @param string $className
     * @return self
     */
    public function alias(string $alias, string $className): self
    {
        $this->middlewareAliases[$alias] = $className;
        return $this;
    }

    /**
     * Create a middleware pipeline for a specific route
     *
     * @param mixed $coreHandler
     * @param array $groups
     * @param string|null $routeName
     * @param array $routeMiddleware
     * @return MiddlewarePipeline
     */
    public function createPipeline(
        $coreHandler,
        array $groups = [],
        ?string $routeName = null,
        array $routeMiddleware = []
    ): MiddlewarePipeline {
        $pipeline = new MiddlewarePipeline($coreHandler);

        // Add global middleware
        foreach ($this->globalMiddleware as $item) {
            $pipeline->through($item['middleware']);
        }

        // Add group middleware
        foreach ($groups as $group) {
            if (isset($this->groupMiddleware[$group])) {
                foreach ($this->groupMiddleware[$group] as $item) {
                    $pipeline->through($item['middleware']);
                }
            }
        }

        // Add route-specific middleware from manager
        if ($routeName && isset($this->routeMiddleware[$routeName])) {
            foreach ($this->routeMiddleware[$routeName] as $item) {
                $pipeline->through($item['middleware']);
            }
        }

        // Add route-specific middleware passed directly
        foreach ($routeMiddleware as $middleware) {
            $pipeline->through($this->resolveMiddleware($middleware));
        }

        return $pipeline;
    }

    /**
     * Get all global middleware
     *
     * @return array
     */
    public function getGlobalMiddleware(): array
    {
        return array_column($this->globalMiddleware, 'middleware');
    }

    /**
     * Get middleware for a specific group
     *
     * @param string $group
     * @return array
     */
    public function getGroupMiddleware(string $group): array
    {
        return array_column($this->groupMiddleware[$group] ?? [], 'middleware');
    }

    /**
     * Get middleware for a specific route
     *
     * @param string $routeName
     * @return array
     */
    public function getRouteMiddleware(string $routeName): array
    {
        return array_column($this->routeMiddleware[$routeName] ?? [], 'middleware');
    }

    /**
     * Resolve middleware (handle aliases and instantiation)
     *
     * @param string|MiddlewareInterface $middleware
     * @return string|MiddlewareInterface
     */
    private function resolveMiddleware($middleware)
    {
        if (is_string($middleware)) {
            // Check if it's an alias
            if (isset($this->middlewareAliases[$middleware])) {
                return $this->middlewareAliases[$middleware];
            }
            
            // Return class name as-is (will be instantiated by pipeline)
            return $middleware;
        }

        // Already an instance
        return $middleware;
    }

    /**
     * Remove global middleware
     *
     * @param string $middlewareClass
     * @return self
     */
    public function removeGlobal(string $middlewareClass): self
    {
        $this->globalMiddleware = array_filter(
            $this->globalMiddleware,
            fn($item) => $item['middleware'] !== $middlewareClass
        );

        return $this;
    }

    /**
     * Remove middleware from group
     *
     * @param string $group
     * @param string $middlewareClass
     * @return self
     */
    public function removeFromGroup(string $group, string $middlewareClass): self
    {
        if (isset($this->groupMiddleware[$group])) {
            $this->groupMiddleware[$group] = array_filter(
                $this->groupMiddleware[$group],
                fn($item) => $item['middleware'] !== $middlewareClass
            );
        }

        return $this;
    }

    /**
     * Clear all middleware from a group
     *
     * @param string $group
     * @return self
     */
    public function clearGroup(string $group): self
    {
        unset($this->groupMiddleware[$group]);
        return $this;
    }

    /**
     * Clear all global middleware
     *
     * @return self
     */
    public function clearGlobal(): self
    {
        $this->globalMiddleware = [];
        return $this;
    }
}