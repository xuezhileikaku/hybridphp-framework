<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\LoadBalancer;

use HybridPHP\Core\Grpc\Discovery\ServiceInstance;

/**
 * Round-robin load balancer
 */
class RoundRobinLoadBalancer implements LoadBalancerInterface
{
    protected int $index = 0;

    /**
     * Select an instance using round-robin
     */
    public function select(array $instances, array $context = []): ?ServiceInstance
    {
        if (empty($instances)) {
            return null;
        }

        $healthyInstances = array_values(array_filter(
            $instances,
            fn(ServiceInstance $i) => $i->healthy
        ));

        if (empty($healthyInstances)) {
            return null;
        }

        $selected = $healthyInstances[$this->index % count($healthyInstances)];
        $this->index++;

        return $selected;
    }

    public function reportSuccess(ServiceInstance $instance, float $latency): void
    {
        // Round-robin doesn't use success metrics
    }

    public function reportFailure(ServiceInstance $instance, \Throwable $error): void
    {
        // Round-robin doesn't use failure metrics
    }

    public function getName(): string
    {
        return 'round_robin';
    }
}
