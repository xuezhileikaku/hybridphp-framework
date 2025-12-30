<?php
namespace HybridPHP\Core\Routing;

/**
 * Router Facade - Provides Yii2-style static methods for routing
 */
class RouterFacade
{
    protected static ?RouterInterface $router = null;

    /**
     * Set the router instance
     */
    public static function setRouter(RouterInterface $router): void
    {
        self::$router = $router;
    }

    /**
     * Get the router instance
     */
    public static function getRouter(): RouterInterface
    {
        if (self::$router === null) {
            self::$router = new Router();
        }
        return self::$router;
    }

    /**
     * Add a GET route
     */
    public static function get(string $path, $handler, array $options = []): RouteInterface
    {
        return self::getRouter()->get($path, $handler, $options);
    }

    /**
     * Add a POST route
     */
    public static function post(string $path, $handler, array $options = []): RouteInterface
    {
        return self::getRouter()->post($path, $handler, $options);
    }

    /**
     * Add a PUT route
     */
    public static function put(string $path, $handler, array $options = []): RouteInterface
    {
        return self::getRouter()->put($path, $handler, $options);
    }

    /**
     * Add a DELETE route
     */
    public static function delete(string $path, $handler, array $options = []): RouteInterface
    {
        return self::getRouter()->delete($path, $handler, $options);
    }

    /**
     * Add a PATCH route
     */
    public static function patch(string $path, $handler, array $options = []): RouteInterface
    {
        return self::getRouter()->patch($path, $handler, $options);
    }

    /**
     * Add a route for any HTTP method
     */
    public static function any(string $path, $handler, array $options = []): RouteInterface
    {
        return self::getRouter()->any($path, $handler, $options);
    }

    /**
     * Add a route for multiple HTTP methods
     */
    public static function match(array $methods, string $path, $handler, array $options = []): RouteInterface
    {
        return self::getRouter()->match($methods, $path, $handler, $options);
    }

    /**
     * Create a route group
     */
    public static function group(array $attributes, callable $callback): void
    {
        self::getRouter()->group($attributes, $callback);
    }

    /**
     * Add a route prefix
     */
    public static function prefix(string $prefix): RouterInterface
    {
        return self::getRouter()->prefix($prefix);
    }

    /**
     * Add middleware to routes
     */
    public static function middleware($middleware): RouterInterface
    {
        return self::getRouter()->middleware($middleware);
    }

    /**
     * Set namespace for routes
     */
    public static function namespace(string $namespace): RouterInterface
    {
        return self::getRouter()->namespace($namespace);
    }

    /**
     * Set name prefix for routes
     */
    public static function name(string $name): RouterInterface
    {
        return self::getRouter()->name($name);
    }

    /**
     * Dispatch a request
     */
    public static function dispatch(string $method, string $uri): array
    {
        return self::getRouter()->dispatch($method, $uri);
    }

    /**
     * Generate URL for named route
     */
    public static function url(string $name, array $parameters = []): string
    {
        return self::getRouter()->url($name, $parameters);
    }

    /**
     * Check if route exists
     */
    public static function has(string $name): bool
    {
        return self::getRouter()->has($name);
    }

    /**
     * Get all registered routes
     */
    public static function getRoutes(): array
    {
        return self::getRouter()->getRoutes();
    }

    /**
     * Clear route cache
     */
    public static function clearCache(): void
    {
        self::getRouter()->clearCache();
    }

    /**
     * Add WebSocket route (extension for HybridPHP)
     */
    public static function websocket(string $path, $handler, array $options = []): RouteInterface
    {
        $options['type'] = 'websocket';
        return self::getRouter()->get($path, $handler, $options);
    }

    /**
     * Add resource routes (RESTful)
     */
    public static function resource(string $name, string $controller, array $options = []): array
    {
        $routes = [];
        $prefix = $options['prefix'] ?? '';
        $middleware = $options['middleware'] ?? [];
        $namespace = $options['namespace'] ?? '';
        
        $basePath = $prefix ? '/' . trim($prefix, '/') . '/' . $name : '/' . $name;
        $controllerClass = $namespace ? $namespace . '\\' . $controller : $controller;
        
        $actions = $options['only'] ?? ['index', 'show', 'store', 'update', 'destroy'];
        if (isset($options['except'])) {
            $actions = array_diff($actions, $options['except']);
        }
        
        $routeOptions = ['middleware' => $middleware];
        
        if (in_array('index', $actions)) {
            $routes['index'] = self::get($basePath, [$controllerClass, 'index'], 
                array_merge($routeOptions, ['name' => $name . '.index']));
        }
        
        if (in_array('show', $actions)) {
            $routes['show'] = self::get($basePath . '/{id}', [$controllerClass, 'show'], 
                array_merge($routeOptions, ['name' => $name . '.show', 'where' => ['id' => '\d+']]));
        }
        
        if (in_array('store', $actions)) {
            $routes['store'] = self::post($basePath, [$controllerClass, 'store'], 
                array_merge($routeOptions, ['name' => $name . '.store']));
        }
        
        if (in_array('update', $actions)) {
            $routes['update'] = self::put($basePath . '/{id}', [$controllerClass, 'update'], 
                array_merge($routeOptions, ['name' => $name . '.update', 'where' => ['id' => '\d+']]));
        }
        
        if (in_array('destroy', $actions)) {
            $routes['destroy'] = self::delete($basePath . '/{id}', [$controllerClass, 'destroy'], 
                array_merge($routeOptions, ['name' => $name . '.destroy', 'where' => ['id' => '\d+']]));
        }
        
        return $routes;
    }

    /**
     * Add API resource routes
     */
    public static function apiResource(string $name, string $controller, array $options = []): array
    {
        $options['only'] = $options['only'] ?? ['index', 'show', 'store', 'update', 'destroy'];
        return self::resource($name, $controller, $options);
    }
}