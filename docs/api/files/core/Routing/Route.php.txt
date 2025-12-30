<?php
namespace HybridPHP\Core\Routing;

class Route implements RouteInterface
{
    protected array $methods;
    protected string $path;
    protected $handler;
    protected ?string $name = null;
    protected array $middleware = [];
    protected array $parameters = [];
    protected array $constraints = [];
    protected ?string $namespace = null;
    protected ?string $prefix = null;
    protected string $compiledPath;
    protected string $originalPath;

    public function __construct(array $methods, string $path, $handler, array $options = [])
    {
        $this->methods = array_map('strtoupper', $methods);
        $this->path = $path;
        $this->handler = $handler;

        // Apply options first, especially constraints
        if (isset($options['name'])) {
            $this->name = $options['name'];
        }
        if (isset($options['middleware'])) {
            $this->middleware = is_array($options['middleware']) ? $options['middleware'] : [$options['middleware']];
        }
        if (isset($options['namespace'])) {
            $this->namespace = $options['namespace'];
        }
        if (isset($options['prefix'])) {
            $this->prefix = $options['prefix'];
        }
        if (isset($options['where'])) {
            $this->constraints = $options['where'];
        }

        // Compile path after constraints are set
        $this->compiledPath = $this->compilePath($path);
    }

    public function getMethods(): array
    {
        return $this->methods;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHandler()
    {
        return $this->handler;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function name(string $name): RouteInterface
    {
        $this->name = $name;
        return $this;
    }

    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function middleware($middleware): RouteInterface
    {
        if (is_array($middleware)) {
            $this->middleware = array_merge($this->middleware, $middleware);
        } else {
            $this->middleware[] = $middleware;
        }
        return $this;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function setParameters(array $parameters): RouteInterface
    {
        $this->parameters = $parameters;
        return $this;
    }

    public function getConstraints(): array
    {
        return $this->constraints;
    }

    public function where(string $parameter, string $pattern): RouteInterface
    {
        $this->constraints[$parameter] = $pattern;
        // Recompile path with new constraints
        $this->compiledPath = $this->compilePath($this->path);
        return $this;
    }

    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    public function setNamespace(string $namespace): RouteInterface
    {
        $this->namespace = $namespace;
        return $this;
    }

    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    public function setPrefix(string $prefix): RouteInterface
    {
        $this->prefix = $prefix;
        return $this;
    }

    public function matches(string $method, string $path): bool
    {
        if (!in_array(strtoupper($method), $this->methods)) {
            return false;
        }

        return $this->matchesPath($path);
    }

    public function url(array $parameters = []): string
    {
        $url = $this->path;
        
        foreach ($parameters as $key => $value) {
            $url = str_replace('{' . $key . '}', $value, $url);
            $url = str_replace('{' . $key . '?}', $value, $url);
        }
        
        // Remove optional parameters that weren't provided
        $url = preg_replace('/\{[^}]+\?\}/', '', $url);
        
        return $url;
    }

    /**
     * Compile route path for matching
     */
    protected function compilePath(string $path): string
    {
        // Store the original path for later use
        $this->originalPath = $path;
        
        // Convert route parameters to regex patterns
        $compiled = preg_replace_callback('/\{([^}]+)\}/', function ($matches) {
            $parameter = $matches[1];
            $optional = false;
            
            // Check if parameter is optional
            if (str_ends_with($parameter, '?')) {
                $parameter = rtrim($parameter, '?');
                $optional = true;
            }
            
            // Apply constraints if any
            if (isset($this->constraints[$parameter])) {
                $pattern = $this->constraints[$parameter];
            } else {
                $pattern = '[^/]+';
            }
            
            return $optional ? "(?:({$pattern}))?" : "({$pattern})";
        }, $path);
        
        return '#^' . $compiled . '$#';
    }

    /**
     * Check if path matches this route
     */
    protected function matchesPath(string $path): bool
    {
        if (preg_match($this->compiledPath, $path, $matches)) {
            // Extract parameters
            $parameters = [];
            $parameterNames = $this->extractParameterNames($this->path);
            
            for ($i = 1; $i < count($matches); $i++) {
                if (isset($parameterNames[$i - 1]) && $matches[$i] !== '') {
                    $parameters[$parameterNames[$i - 1]] = $matches[$i];
                }
            }
            
            $this->parameters = $parameters;
            return true;
        }
        
        return false;
    }

    /**
     * Extract parameter names from path
     */
    protected function extractParameterNames(string $path): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $matches);
        return array_map(function ($param) {
            return rtrim($param, '?');
        }, $matches[1]);
    }

    /**
     * Get the full path including prefix
     */
    public function getFullPath(): string
    {
        $path = $this->path;
        
        if ($this->prefix) {
            $path = '/' . trim($this->prefix, '/') . '/' . ltrim($path, '/');
        }
        
        return $path;
    }

    /**
     * Get the full handler including namespace
     */
    public function getFullHandler()
    {
        $handler = $this->handler;
        
        if (is_string($handler) && $this->namespace) {
            $handler = $this->namespace . '\\' . $handler;
        } elseif (is_array($handler) && is_string($handler[0]) && $this->namespace) {
            $handler[0] = $this->namespace . '\\' . $handler[0];
        }
        
        return $handler;
    }
}