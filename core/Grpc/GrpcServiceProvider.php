<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc;

use HybridPHP\Core\Container;
use HybridPHP\Core\Grpc\Discovery\ServiceDiscoveryInterface;
use HybridPHP\Core\Grpc\Discovery\InMemoryServiceDiscovery;
use HybridPHP\Core\Grpc\Discovery\ConsulServiceDiscovery;
use HybridPHP\Core\Grpc\LoadBalancer\LoadBalancerInterface;
use HybridPHP\Core\Grpc\LoadBalancer\RoundRobinLoadBalancer;
use HybridPHP\Core\Grpc\LoadBalancer\WeightedLoadBalancer;
use HybridPHP\Core\Grpc\LoadBalancer\LeastConnectionsLoadBalancer;
use HybridPHP\Core\Grpc\LoadBalancer\ConsistentHashLoadBalancer;
use Psr\Log\LoggerInterface;

/**
 * gRPC Service Provider
 */
class GrpcServiceProvider
{
    protected Container $container;
    protected array $config;

    public function __construct(Container $container, array $config = [])
    {
        $this->container = $container;
        $this->config = array_merge([
            'enabled' => true,
            'server' => [
                'host' => '0.0.0.0',
                'port' => 50051,
                'maxConcurrentStreams' => 100,
                'maxMessageSize' => 4 * 1024 * 1024,
                'reflection' => true,
                'tls' => null,
            ],
            'client' => [
                'timeout' => 30,
                'retries' => 3,
                'retryDelay' => 0.1,
            ],
            'discovery' => [
                'driver' => 'memory', // memory, consul, etcd
                'consul' => [
                    'host' => 'localhost',
                    'port' => 8500,
                ],
            ],
            'loadBalancer' => 'round_robin', // round_robin, weighted, least_connections, consistent_hash
            'interceptors' => [],
        ], $config);
    }

    /**
     * Register gRPC services
     */
    public function register(): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        // Register service discovery
        $this->registerServiceDiscovery();

        // Register load balancer
        $this->registerLoadBalancer();

        // Register gRPC server
        $this->container->singleton(GrpcServer::class, function () {
            $server = new GrpcServer(
                $this->config['server'],
                $this->container->has(LoggerInterface::class) 
                    ? $this->container->get(LoggerInterface::class) 
                    : null
            );

            // Set service discovery if available
            if ($this->container->has(ServiceDiscoveryInterface::class)) {
                $server->setServiceDiscovery($this->container->get(ServiceDiscoveryInterface::class));
            }

            // Set load balancer
            if ($this->container->has(LoadBalancerInterface::class)) {
                $server->setLoadBalancer($this->container->get(LoadBalancerInterface::class));
            }

            // Add interceptors
            foreach ($this->config['interceptors'] as $interceptorClass) {
                if ($this->container->has($interceptorClass)) {
                    $server->addInterceptor($this->container->get($interceptorClass));
                } elseif (class_exists($interceptorClass)) {
                    $server->addInterceptor(new $interceptorClass());
                }
            }

            return $server;
        });

        // Register gRPC client
        $this->container->singleton(GrpcClient::class, function () {
            $client = new GrpcClient(
                $this->config['client'],
                $this->container->has(LoggerInterface::class) 
                    ? $this->container->get(LoggerInterface::class) 
                    : null
            );

            // Set service discovery if available
            if ($this->container->has(ServiceDiscoveryInterface::class)) {
                $client->setServiceDiscovery($this->container->get(ServiceDiscoveryInterface::class));
            }

            // Set load balancer
            if ($this->container->has(LoadBalancerInterface::class)) {
                $client->setLoadBalancer($this->container->get(LoadBalancerInterface::class));
            }

            return $client;
        });
    }

    /**
     * Register service discovery
     */
    protected function registerServiceDiscovery(): void
    {
        $driver = $this->config['discovery']['driver'];

        $this->container->singleton(ServiceDiscoveryInterface::class, function () use ($driver) {
            return match ($driver) {
                'consul' => new ConsulServiceDiscovery(
                    $this->config['discovery']['consul'],
                    $this->container->has(LoggerInterface::class) 
                        ? $this->container->get(LoggerInterface::class) 
                        : null
                ),
                default => new InMemoryServiceDiscovery(),
            };
        });
    }

    /**
     * Register load balancer
     */
    protected function registerLoadBalancer(): void
    {
        $strategy = $this->config['loadBalancer'];

        $this->container->singleton(LoadBalancerInterface::class, function () use ($strategy) {
            return match ($strategy) {
                'weighted' => new WeightedLoadBalancer(),
                'least_connections' => new LeastConnectionsLoadBalancer(),
                'consistent_hash' => new ConsistentHashLoadBalancer(),
                default => new RoundRobinLoadBalancer(),
            };
        });
    }

    /**
     * Boot gRPC services
     */
    public function boot(): void
    {
        // Additional boot logic if needed
    }

    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
