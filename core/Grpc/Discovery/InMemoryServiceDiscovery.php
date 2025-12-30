<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\Discovery;

use Amp\Future;
use function Amp\async;

/**
 * In-memory service discovery for development and testing
 */
class InMemoryServiceDiscovery implements ServiceDiscoveryInterface
{
    /** @var array<string, array<string, ServiceInstance>> */
    protected array $services = [];
    
    /** @var array<string, array<callable>> */
    protected array $watchers = [];

    /**
     * Register a service instance
     */
    public function register(string $serviceName, array $instance): Future
    {
        return async(function () use ($serviceName, $instance) {
            $serviceInstance = ServiceInstance::fromArray(array_merge(
                $instance,
                ['serviceName' => $serviceName]
            ));

            if (!isset($this->services[$serviceName])) {
                $this->services[$serviceName] = [];
            }

            $this->services[$serviceName][$serviceInstance->id] = $serviceInstance;
            $this->notifyWatchers($serviceName, 'register', $serviceInstance);

            return true;
        });
    }

    /**
     * Deregister a service instance
     */
    public function deregister(string $serviceName, ?string $instanceId = null): Future
    {
        return async(function () use ($serviceName, $instanceId) {
            if (!isset($this->services[$serviceName])) {
                return false;
            }

            if ($instanceId !== null) {
                if (isset($this->services[$serviceName][$instanceId])) {
                    $instance = $this->services[$serviceName][$instanceId];
                    unset($this->services[$serviceName][$instanceId]);
                    $this->notifyWatchers($serviceName, 'deregister', $instance);
                }
            } else {
                // Deregister all instances
                foreach ($this->services[$serviceName] as $instance) {
                    $this->notifyWatchers($serviceName, 'deregister', $instance);
                }
                unset($this->services[$serviceName]);
            }

            return true;
        });
    }

    /**
     * Discover service instances
     */
    public function discover(string $serviceName): Future
    {
        return async(function () use ($serviceName) {
            if (!isset($this->services[$serviceName])) {
                return [];
            }

            return array_values(array_filter(
                $this->services[$serviceName],
                fn(ServiceInstance $instance) => $instance->healthy
            ));
        });
    }

    /**
     * Watch for service changes
     */
    public function watch(string $serviceName, callable $callback): Future
    {
        return async(function () use ($serviceName, $callback) {
            if (!isset($this->watchers[$serviceName])) {
                $this->watchers[$serviceName] = [];
            }

            $this->watchers[$serviceName][] = $callback;
        });
    }

    /**
     * Get all registered services
     */
    public function getServices(): Future
    {
        return async(fn() => array_keys($this->services));
    }

    /**
     * Health check for a service instance
     */
    public function healthCheck(string $serviceName, string $instanceId): Future
    {
        return async(function () use ($serviceName, $instanceId) {
            if (!isset($this->services[$serviceName][$instanceId])) {
                return false;
            }

            return $this->services[$serviceName][$instanceId]->healthy;
        });
    }

    /**
     * Update instance health status
     */
    public function updateHealth(string $serviceName, string $instanceId, bool $healthy): void
    {
        if (isset($this->services[$serviceName][$instanceId])) {
            $old = $this->services[$serviceName][$instanceId];
            $this->services[$serviceName][$instanceId] = new ServiceInstance(
                id: $old->id,
                serviceName: $old->serviceName,
                host: $old->host,
                port: $old->port,
                metadata: $old->metadata,
                healthy: $healthy,
                weight: $old->weight,
                zone: $old->zone,
            );

            $this->notifyWatchers($serviceName, 'health', $this->services[$serviceName][$instanceId]);
        }
    }

    /**
     * Notify watchers of changes
     */
    protected function notifyWatchers(string $serviceName, string $event, ServiceInstance $instance): void
    {
        if (!isset($this->watchers[$serviceName])) {
            return;
        }

        foreach ($this->watchers[$serviceName] as $callback) {
            try {
                $callback($event, $instance);
            } catch (\Throwable $e) {
                // Log error but continue notifying other watchers
            }
        }
    }
}
