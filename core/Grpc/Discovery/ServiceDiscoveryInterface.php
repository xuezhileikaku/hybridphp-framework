<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\Discovery;

use Amp\Future;

/**
 * Interface for service discovery implementations
 */
interface ServiceDiscoveryInterface
{
    /**
     * Register a service instance
     *
     * @param string $serviceName The service name
     * @param array $instance Instance details (host, port, metadata)
     * @return Future<bool>
     */
    public function register(string $serviceName, array $instance): Future;

    /**
     * Deregister a service instance
     *
     * @param string $serviceName The service name
     * @param string|null $instanceId Optional specific instance ID
     * @return Future<bool>
     */
    public function deregister(string $serviceName, ?string $instanceId = null): Future;

    /**
     * Discover service instances
     *
     * @param string $serviceName The service name
     * @return Future<array<ServiceInstance>>
     */
    public function discover(string $serviceName): Future;

    /**
     * Watch for service changes
     *
     * @param string $serviceName The service name
     * @param callable $callback Callback for changes
     * @return Future<void>
     */
    public function watch(string $serviceName, callable $callback): Future;

    /**
     * Get all registered services
     *
     * @return Future<array<string>>
     */
    public function getServices(): Future;

    /**
     * Health check for a service instance
     *
     * @param string $serviceName The service name
     * @param string $instanceId The instance ID
     * @return Future<bool>
     */
    public function healthCheck(string $serviceName, string $instanceId): Future;
}
