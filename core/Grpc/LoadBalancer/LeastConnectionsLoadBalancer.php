<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\LoadBalancer;

use HybridPHP\Core\Grpc\Discovery\ServiceInstance;

/**
 * Least connections load balancer
 */
class LeastConnectionsLoadBalancer implements LoadBalancerInterface
{
    /** @var array<string, int> */
    protected array $connections = [];

    /**
     * Select the instance with the least active connections
     */
    public function select(array $instances, array $context = []): ?ServiceInstance
    {
        if (empty($instances)) {
            return null;
        }

        $healthyInstances = array_filter(
            $instances,
            fn(ServiceInstance $i) => $i->healthy
        );

        if (empty($healthyInstances)) {
            return null;
        }

        // Find instance with least connections
        $selected = null;
        $minConnections = PHP_INT_MAX;

        foreach ($healthyInstances as $instance) {
            $connections = $this->connections[$instance->id] ?? 0;
            if ($connections < $minConnections) {
                $minConnections = $connections;
                $selected = $instance;
            }
        }

        if ($selected) {
            $this->connections[$selected->id] = ($this->connections[$selected->id] ?? 0) + 1;
        }

        return $selected;
    }

    public function reportSuccess(ServiceInstance $instance, float $latency): void
    {
        $this->decrementConnections($instance);
    }

    public function reportFailure(ServiceInstance $instance, \Throwable $error): void
    {
        $this->decrementConnections($instance);
    }

    protected function decrementConnections(ServiceInstance $instance): void
    {
        if (isset($this->connections[$instance->id])) {
            $this->connections[$instance->id] = max(0, $this->connections[$instance->id] - 1);
        }
    }

    public function getName(): string
    {
        return 'least_connections';
    }

    /**
     * Get current connection counts
     */
    public function getConnectionCounts(): array
    {
        return $this->connections;
    }
}
