<?php
namespace HybridPHP\Core\Routing;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use function FastRoute\simpleDispatcher;
use function FastRoute\cachedDispatcher;

class Router implements RouterInterface
{
    protected array $routes = [];
    protected array $namedRoutes = [];
    protected array $groupStack = [];
    protected ?Dispatcher $dispatcher = null;
    protected bool $cacheEnabled = false;
    protected string $cacheFile = '';
    
    // Current group attributes
    protected array $currentGroupAttributes = [];

    public function __construct(array $options = [])
    {
        $this->cacheEnabled = $options['cache'] ?? false;
        $this->cacheFile = $options['cache_file'] ?? sys_get_temp_dir() . '/hybrid_routes.cache';
    }

    public function get(string $path, $handler, array $options = []): RouteInterface
    {
        return $this->addRoute(['GET'], $path, $handler, $options);
    }

    public function post(string $path, $handler, array $options = []): RouteInterface
    {
        return $this->addRoute(['POST'], $path, $handler, $options);
    }

    public function put(string $path, $handler, array $options = []): RouteInterface
    {
        return $this->addRoute(['PUT'], $path, $handler, $options);
    }

    public function delete(string $path, $handler, array $options = []): RouteInterface
    {
        return $this->addRoute(['DELETE'], $path, $handler, $options);
    }

    public function patch(string $path, $handler, array $options = []): RouteInterface
    {
        return $this->addRoute(['PATCH'], $path, $handler, $options);
    }

    public function any(string $path, $handler, array $options = []): RouteInterface
    {
        return $this->addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'], $path, $handler, $options);
    }

    public function match(array $methods, string $path, $handler, array $options = []): RouteInterface
    {
        return $this->addRoute($methods, $path, $handler, $options);
    }

    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = $attributes;
        $this->updateCurrentGroupAttributes();
        
        $callback($this);
        
        array_pop($this->groupStack);
        $this->updateCurrentGroupAttributes();
    }

    public function prefix(string $prefix): RouterInterface
    {
        $router = clone $this;
        $router->currentGroupAttributes['prefix'] = $this->formatPrefix($prefix);
        return $router;
    }

    public function middleware($middleware): RouterInterface
    {
        $router = clone $this;
        $router->currentGroupAttributes['middleware'] = is_array($middleware) ? $middleware : [$middleware];
        return $router;
    }

    public function namespace(string $namespace): RouterInterface
    {
        $router = clone $this;
        $router->currentGroupAttributes['namespace'] = $namespace;
        return $router;
    }

    public function name(string $name): RouterInterface
    {
        $router = clone $this;
        $router->currentGroupAttributes['name'] = $name;
        return $router;
    }

    public function dispatch(string $method, string $uri): array
    {
        if ($this->dispatcher === null) {
            $this->buildDispatcher();
        }

        $routeInfo = $this->dispatcher->dispatch($method, $uri);
        
        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                return [404, null, []];
            case Dispatcher::METHOD_NOT_ALLOWED:
                return [405, null, $routeInfo[1]]; // allowed methods
            case Dispatcher::FOUND:
                return [200, $routeInfo[1], $routeInfo[2]]; // handler, vars
            default:
                return [500, null, []];
        }
    }

    public function url(string $name, array $parameters = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \InvalidArgumentException("Route '{$name}' not found");
        }

        return $this->namedRoutes[$name]->url($parameters);
    }

    public function has(string $name): bool
    {
        return isset($this->namedRoutes[$name]);
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function clearCache(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
        $this->dispatcher = null;
    }

    /**
     * Add a route
     */
    protected function addRoute(array $methods, string $path, $handler, array $options = []): RouteInterface
    {
        // Apply group attributes
        $options = $this->mergeGroupAttributes($options);
        
        // Apply prefix
        if (isset($options['prefix'])) {
            $path = '/' . trim($options['prefix'], '/') . '/' . ltrim($path, '/');
            $path = rtrim($path, '/') ?: '/';
        }
        
        // Apply namespace to handler
        if (isset($options['namespace']) && is_string($handler)) {
            $handler = $options['namespace'] . '\\' . $handler;
        } elseif (isset($options['namespace']) && is_array($handler) && is_string($handler[0])) {
            $handler[0] = $options['namespace'] . '\\' . $handler[0];
        }

        $route = new Route($methods, $path, $handler, $options);
        
        // Add to routes collection
        $this->routes[] = $route;
        
        // Add to named routes if name is provided
        $routeName = $route->getName();
        if ($routeName) {
            $this->namedRoutes[$routeName] = $route;
        }
        
        // Also check if name was set in options
        if (isset($options['name']) && !$routeName) {
            $route->name($options['name']);
            $this->namedRoutes[$options['name']] = $route;
        }
        
        // Clear dispatcher cache
        $this->dispatcher = null;
        
        return $route;
    }

    /**
     * Build FastRoute dispatcher
     */
    protected function buildDispatcher(): void
    {
        $dispatcherFactory = $this->cacheEnabled ? 'FastRoute\cachedDispatcher' : 'FastRoute\simpleDispatcher';
        
        $options = [];
        if ($this->cacheEnabled) {
            $options['cacheFile'] = $this->cacheFile;
        }

        $this->dispatcher = $dispatcherFactory(function (RouteCollector $r) {
            foreach ($this->routes as $route) {
                $r->addRoute(
                    $route->getMethods(),
                    $route->getPath(),
                    [
                        'handler' => $route->getHandler(),
                        'middleware' => $route->getMiddleware(),
                        'route' => $route
                    ]
                );
            }
        }, $options);
    }

    /**
     * Update current group attributes
     */
    protected function updateCurrentGroupAttributes(): void
    {
        $this->currentGroupAttributes = [];
        
        foreach ($this->groupStack as $group) {
            $this->currentGroupAttributes = $this->mergeGroupAttributes($this->currentGroupAttributes, $group);
        }
    }

    /**
     * Merge group attributes
     */
    protected function mergeGroupAttributes(array $current, array $new = null): array
    {
        if ($new === null) {
            $new = $this->currentGroupAttributes;
        }
        
        $merged = $current;
        
        // Merge prefix
        if (isset($new['prefix'])) {
            $prefix = isset($merged['prefix']) ? $merged['prefix'] : '';
            $merged['prefix'] = $this->formatPrefix($prefix . '/' . $new['prefix']);
        }
        
        // Merge namespace
        if (isset($new['namespace'])) {
            $namespace = isset($merged['namespace']) ? $merged['namespace'] : '';
            $merged['namespace'] = $namespace ? $namespace . '\\' . $new['namespace'] : $new['namespace'];
        }
        
        // Merge middleware
        if (isset($new['middleware'])) {
            $middleware = isset($merged['middleware']) ? $merged['middleware'] : [];
            $newMiddleware = is_array($new['middleware']) ? $new['middleware'] : [$new['middleware']];
            $merged['middleware'] = array_merge($middleware, $newMiddleware);
        }
        
        // Merge name
        if (isset($new['name'])) {
            $name = isset($merged['name']) ? $merged['name'] : '';
            $merged['name'] = $name ? $name . '.' . $new['name'] : $new['name'];
        }
        
        // Merge other attributes
        foreach (['where', 'domain'] as $key) {
            if (isset($new[$key])) {
                $merged[$key] = isset($merged[$key]) ? array_merge($merged[$key], $new[$key]) : $new[$key];
            }
        }
        
        return $merged;
    }

    /**
     * Format prefix
     */
    protected function formatPrefix(string $prefix): string
    {
        return '/' . trim($prefix, '/');
    }

    /**
     * Static methods for global router instance (Yii2 style)
     */
    protected static ?Router $instance = null;

    public static function getInstance(): Router
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    public static function setInstance(Router $router): void
    {
        self::$instance = $router;
    }

    // Static convenience methods
    public static function staticGet(string $path, $handler, array $options = []): RouteInterface
    {
        return self::getInstance()->get($path, $handler, $options);
    }

    public static function staticPost(string $path, $handler, array $options = []): RouteInterface
    {
        return self::getInstance()->post($path, $handler, $options);
    }

    public static function staticPut(string $path, $handler, array $options = []): RouteInterface
    {
        return self::getInstance()->put($path, $handler, $options);
    }

    public static function staticDelete(string $path, $handler, array $options = []): RouteInterface
    {
        return self::getInstance()->delete($path, $handler, $options);
    }

    public static function staticPatch(string $path, $handler, array $options = []): RouteInterface
    {
        return self::getInstance()->patch($path, $handler, $options);
    }

    public static function staticAny(string $path, $handler, array $options = []): RouteInterface
    {
        return self::getInstance()->any($path, $handler, $options);
    }

    public static function staticMatch(array $methods, string $path, $handler, array $options = []): RouteInterface
    {
        return self::getInstance()->match($methods, $path, $handler, $options);
    }

    public static function staticGroup(array $attributes, callable $callback): void
    {
        self::getInstance()->group($attributes, $callback);
    }

    public static function staticDispatch(string $method, string $uri): array
    {
        return self::getInstance()->dispatch($method, $uri);
    }

    public static function staticUrl(string $name, array $parameters = []): string
    {
        return self::getInstance()->url($name, $parameters);
    }
}
