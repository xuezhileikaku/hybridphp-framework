<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\LoadBalancer;

use HybridPHP\Core\Grpc\Discovery\ServiceInstance;

/**
 * Interface for load balancer implementations
 */
interface LoadBalancerInterface
{
    /**
     * Select an instance from the available instances
     *
     * @param array<ServiceInstance> $instances Available instances
     * @param array $context Optional context for selection (e.g., request metadata)
     * @return ServiceInstance|null Selected instance or null if none available
     */
    public function select(array $instances, array $context = []): ?ServiceInstance;

    /**
     * Report a successful request to an instance
     */
    public function reportSuccess(ServiceInstance $instance, float $latency): void;

    /**
     * Report a failed request to an instance
     */
    public function reportFailure(ServiceInstance $instance, \Throwable $error): void;

    /**
     * Get the name of the load balancing strategy
     */
    public function getName(): string;
}
