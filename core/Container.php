<?php
namespace HybridPHP\Core;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use InvalidArgumentException;

class Container implements ContainerInterface
{
    protected static ?Container $instance = null;
    protected array $bindings = [];
    protected array $instances = [];
    protected array $singletons = [];
    protected array $aliases = [];
    protected array $building = [];

    /**
     * Get the singleton instance of the container
     */
    public static function getInstance(): ?Container
    {
        return self::$instance;
    }

    /**
     * Set the singleton instance of the container
     */
    public static function setInstance(?Container $container): void
    {
        self::$instance = $container;
    }

    public function __construct()
    {
        if (self::$instance === null) {
            self::$instance = $this;
        }
    }

    /**
     * Bind a service to the container
     */
    public function bind(string $abstract, $concrete = null, bool $shared = false): void
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }

        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => $shared
        ];

        if (isset($this->instances[$abstract])) {
            unset($this->instances[$abstract]);
        }
    }

    /**
     * Register a singleton binding
     */
    public function singleton(string $abstract, $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Register an existing instance as shared
     */
    public function instance(string $abstract, $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * Register an alias for a service
     */
    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    /**
     * Get a service from the container
     */
    public function get($id)
    {
        try {
            return $this->resolve($id);
        } catch (ReflectionException $e) {
            throw new ContainerException("Unable to resolve [{$id}]: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if the container can return an entry for the given identifier
     */
    public function has($id): bool
    {
        return isset($this->bindings[$id]) || 
               isset($this->instances[$id]) || 
               isset($this->aliases[$id]) ||
               class_exists($id);
    }

    /**
     * Resolve a service from the container
     */
    protected function resolve(string $abstract)
    {
        // Check for alias
        if (isset($this->aliases[$abstract])) {
            $abstract = $this->aliases[$abstract];
        }

        // Return existing instance if it's a singleton
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Check for circular dependency
        if (isset($this->building[$abstract])) {
            throw new RuntimeException("Circular dependency detected while resolving [{$abstract}]");
        }

        $this->building[$abstract] = true;

        try {
            $concrete = $this->getConcrete($abstract);
            $object = $this->build($concrete);

            // Store instance if it's shared
            if ($this->isShared($abstract)) {
                $this->instances[$abstract] = $object;
            }

            unset($this->building[$abstract]);
            return $object;
        } catch (\Exception $e) {
            unset($this->building[$abstract]);
            throw $e;
        }
    }

    /**
     * Get the concrete implementation for an abstract
     */
    protected function getConcrete(string $abstract)
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
    }

    /**
     * Check if a service is shared (singleton)
     */
    protected function isShared(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) && $this->bindings[$abstract]['shared'];
    }

    /**
     * Build a concrete instance
     */
    protected function build($concrete)
    {
        // If concrete is a closure, call it
        if ($concrete instanceof \Closure) {
            return $concrete($this);
        }

        // If concrete is not a class name, return as is
        if (!is_string($concrete) || !class_exists($concrete)) {
            return $concrete;
        }

        try {
            $reflection = new ReflectionClass($concrete);
        } catch (ReflectionException $e) {
            throw new ContainerException("Target class [{$concrete}] does not exist.", 0, $e);
        }

        if (!$reflection->isInstantiable()) {
            throw new ContainerException("Target [{$concrete}] is not instantiable.");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $concrete;
        }

        $dependencies = $this->resolveDependencies($constructor->getParameters());

        return $reflection->newInstanceArgs($dependencies);
    }

    /**
     * Resolve constructor dependencies
     */
    protected function resolveDependencies(array $parameters): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if ($type === null) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new ContainerException("Unable to resolve parameter [{$parameter->getName()}] without type hint");
                }
            } elseif ($type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new ContainerException("Unable to resolve built-in parameter [{$parameter->getName()}]");
                }
            } else {
                $dependencies[] = $this->resolve($type->getName());
            }
        }

        return $dependencies;
    }

    /**
     * Clear all bindings and instances
     */
    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->aliases = [];
        $this->building = [];
    }

    /**
     * Get all registered bindings
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }
}

/**
 * Container exception class
 */
class ContainerException extends \Exception implements NotFoundExceptionInterface
{
}
