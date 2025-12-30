<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\LoadBalancer;

use HybridPHP\Core\Grpc\Discovery\ServiceInstance;

/**
 * Consistent hash load balancer for sticky sessions
 */
class ConsistentHashLoadBalancer implements LoadBalancerInterface
{
    protected int $virtualNodes;
    
    /** @var array<int, string> */
    protected array $ring = [];
    
    /** @var array<string, ServiceInstance> */
    protected array $instanceMap = [];

    public function __construct(int $virtualNodes = 150)
    {
        $this->virtualNodes = $virtualNodes;
    }

    /**
     * Select an instance based on consistent hashing
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

        // Rebuild ring if instances changed
        $this->buildRing($healthyInstances);

        // Get hash key from context or generate random
        $key = $context['hash_key'] ?? $context['request_id'] ?? uniqid();
        $hash = $this->hash($key);

        // Find the first node in the ring >= hash
        $keys = array_keys($this->ring);
        sort($keys);

        foreach ($keys as $nodeHash) {
            if ($nodeHash >= $hash) {
                return $this->instanceMap[$this->ring[$nodeHash]];
            }
        }

        // Wrap around to first node
        return $this->instanceMap[$this->ring[$keys[0]]];
    }

    /**
     * Build the hash ring
     *
     * @param array<ServiceInstance> $instances
     */
    protected function buildRing(array $instances): void
    {
        $this->ring = [];
        $this->instanceMap = [];

        foreach ($instances as $instance) {
            $this->instanceMap[$instance->id] = $instance;

            for ($i = 0; $i < $this->virtualNodes; $i++) {
                $virtualKey = "{$instance->id}:{$i}";
                $hash = $this->hash($virtualKey);
                $this->ring[$hash] = $instance->id;
            }
        }
    }

    /**
     * Hash a key to a 32-bit integer
     */
    protected function hash(string $key): int
    {
        return crc32($key);
    }

    public function reportSuccess(ServiceInstance $instance, float $latency): void
    {
        // Consistent hash doesn't use success metrics
    }

    public function reportFailure(ServiceInstance $instance, \Throwable $error): void
    {
        // Consistent hash doesn't use failure metrics
    }

    public function getName(): string
    {
        return 'consistent_hash';
    }
}
