<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\LoadBalancer;

use HybridPHP\Core\Grpc\Discovery\ServiceInstance;

/**
 * Weighted load balancer based on instance weights
 */
class WeightedLoadBalancer implements LoadBalancerInterface
{
    /**
     * Select an instance based on weights
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

        // Calculate total weight
        $totalWeight = array_sum(array_map(
            fn(ServiceInstance $i) => $i->weight,
            $healthyInstances
        ));

        if ($totalWeight <= 0) {
            // Fall back to random selection
            return $healthyInstances[array_rand($healthyInstances)];
        }

        // Random weighted selection
        $random = mt_rand(1, $totalWeight);
        $cumulative = 0;

        foreach ($healthyInstances as $instance) {
            $cumulative += $instance->weight;
            if ($random <= $cumulative) {
                return $instance;
            }
        }

        // Fallback (shouldn't reach here)
        return reset($healthyInstances);
    }

    public function reportSuccess(ServiceInstance $instance, float $latency): void
    {
        // Weighted doesn't dynamically adjust weights
    }

    public function reportFailure(ServiceInstance $instance, \Throwable $error): void
    {
        // Weighted doesn't dynamically adjust weights
    }

    public function getName(): string
    {
        return 'weighted';
    }
}
