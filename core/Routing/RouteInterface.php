<?php
namespace HybridPHP\Core\Routing;

interface RouteInterface
{
    /**
     * Get route methods
     */
    public function getMethods(): array;

    /**
     * Get route path
     */
    public function getPath(): string;

    /**
     * Get route handler
     */
    public function getHandler();

    /**
     * Get route name
     */
    public function getName(): ?string;

    /**
     * Set route name
     */
    public function name(string $name): RouteInterface;

    /**
     * Get route middleware
     */
    public function getMiddleware(): array;

    /**
     * Add middleware to route
     */
    public function middleware($middleware): RouteInterface;

    /**
     * Get route parameters
     */
    public function getParameters(): array;

    /**
     * Set route parameters
     */
    public function setParameters(array $parameters): RouteInterface;

    /**
     * Get route constraints
     */
    public function getConstraints(): array;

    /**
     * Add constraint to route parameter
     */
    public function where(string $parameter, string $pattern): RouteInterface;

    /**
     * Get route namespace
     */
    public function getNamespace(): ?string;

    /**
     * Set route namespace
     */
    public function setNamespace(string $namespace): RouteInterface;

    /**
     * Get route prefix
     */
    public function getPrefix(): ?string;

    /**
     * Set route prefix
     */
    public function setPrefix(string $prefix): RouteInterface;

    /**
     * Check if route matches given method and path
     */
    public function matches(string $method, string $path): bool;

    /**
     * Generate URL for this route
     */
    public function url(array $parameters = []): string;
}