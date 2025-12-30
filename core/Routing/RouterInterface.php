<?php
namespace HybridPHP\Core\Routing;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

interface RouterInterface
{
    /**
     * Add a GET route
     */
    public function get(string $path, $handler, array $options = []): RouteInterface;

    /**
     * Add a POST route
     */
    public function post(string $path, $handler, array $options = []): RouteInterface;

    /**
     * Add a PUT route
     */
    public function put(string $path, $handler, array $options = []): RouteInterface;

    /**
     * Add a DELETE route
     */
    public function delete(string $path, $handler, array $options = []): RouteInterface;

    /**
     * Add a PATCH route
     */
    public function patch(string $path, $handler, array $options = []): RouteInterface;

    /**
     * Add a route for any HTTP method
     */
    public function any(string $path, $handler, array $options = []): RouteInterface;

    /**
     * Add a route for multiple HTTP methods
     */
    public function match(array $methods, string $path, $handler, array $options = []): RouteInterface;

    /**
     * Create a route group
     */
    public function group(array $attributes, callable $callback): void;

    /**
     * Add a route prefix
     */
    public function prefix(string $prefix): RouterInterface;

    /**
     * Add middleware to routes
     */
    public function middleware($middleware): RouterInterface;

    /**
     * Set namespace for routes
     */
    public function namespace(string $namespace): RouterInterface;

    /**
     * Set name prefix for routes
     */
    public function name(string $name): RouterInterface;

    /**
     * Dispatch a request
     */
    public function dispatch(string $method, string $uri): array;

    /**
     * Generate URL for named route
     */
    public function url(string $name, array $parameters = []): string;

    /**
     * Check if route exists
     */
    public function has(string $name): bool;

    /**
     * Get all registered routes
     */
    public function getRoutes(): array;

    /**
     * Clear route cache
     */
    public function clearCache(): void;
}
