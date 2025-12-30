<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\Discovery;

use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function Amp\async;

/**
 * Consul-based service discovery implementation
 */
class ConsulServiceDiscovery implements ServiceDiscoveryInterface
{
    protected HttpClient $httpClient;
    protected string $consulUrl;
    protected LoggerInterface $logger;
    protected array $config;
    protected ?string $localInstanceId = null;

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'host' => 'localhost',
            'port' => 8500,
            'scheme' => 'http',
            'token' => null,
            'datacenter' => null,
            'healthCheckInterval' => '10s',
            'deregisterCriticalServiceAfter' => '1m',
        ], $config);

        $this->consulUrl = sprintf(
            '%s://%s:%d',
            $this->config['scheme'],
            $this->config['host'],
            $this->config['port']
        );

        $this->httpClient = HttpClientBuilder::buildDefault();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Register a service instance
     */
    public function register(string $serviceName, array $instance): Future
    {
        return async(function () use ($serviceName, $instance) {
            $instanceId = $instance['id'] ?? uniqid("{$serviceName}_");
            $this->localInstanceId = $instanceId;

            $registration = [
                'ID' => $instanceId,
                'Name' => $serviceName,
                'Address' => $instance['host'] ?? 'localhost',
                'Port' => $instance['port'] ?? 50051,
                'Tags' => $instance['tags'] ?? ['grpc'],
                'Meta' => $instance['metadata'] ?? [],
                'Check' => [
                    'GRPC' => sprintf('%s:%d', $instance['host'] ?? 'localhost', $instance['port'] ?? 50051),
                    'Interval' => $this->config['healthCheckInterval'],
                    'DeregisterCriticalServiceAfter' => $this->config['deregisterCriticalServiceAfter'],
                ],
            ];

            $request = new Request(
                "{$this->consulUrl}/v1/agent/service/register",
                'PUT'
            );
            $request->setBody(json_encode($registration));
            $request->setHeader('Content-Type', 'application/json');
            
            if ($this->config['token']) {
                $request->setHeader('X-Consul-Token', $this->config['token']);
            }

            $response = $this->httpClient->request($request);
            
            if ($response->getStatus() !== 200) {
                $this->logger->error('Failed to register service with Consul', [
                    'service' => $serviceName,
                    'status' => $response->getStatus(),
                ]);
                return false;
            }

            $this->logger->info('Registered service with Consul', [
                'service' => $serviceName,
                'instanceId' => $instanceId,
            ]);

            return true;
        });
    }

    /**
     * Deregister a service instance
     */
    public function deregister(string $serviceName, ?string $instanceId = null): Future
    {
        return async(function () use ($serviceName, $instanceId) {
            $id = $instanceId ?? $this->localInstanceId;
            
            if (!$id) {
                return false;
            }

            $request = new Request(
                "{$this->consulUrl}/v1/agent/service/deregister/{$id}",
                'PUT'
            );

            if ($this->config['token']) {
                $request->setHeader('X-Consul-Token', $this->config['token']);
            }

            $response = $this->httpClient->request($request);

            if ($response->getStatus() !== 200) {
                $this->logger->error('Failed to deregister service from Consul', [
                    'service' => $serviceName,
                    'instanceId' => $id,
                ]);
                return false;
            }

            $this->logger->info('Deregistered service from Consul', [
                'service' => $serviceName,
                'instanceId' => $id,
            ]);

            return true;
        });
    }

    /**
     * Discover service instances
     */
    public function discover(string $serviceName): Future
    {
        return async(function () use ($serviceName) {
            $url = "{$this->consulUrl}/v1/health/service/{$serviceName}?passing=true";
            
            if ($this->config['datacenter']) {
                $url .= "&dc={$this->config['datacenter']}";
            }

            $request = new Request($url);
            
            if ($this->config['token']) {
                $request->setHeader('X-Consul-Token', $this->config['token']);
            }

            $response = $this->httpClient->request($request);
            
            if ($response->getStatus() !== 200) {
                $this->logger->error('Failed to discover services from Consul', [
                    'service' => $serviceName,
                ]);
                return [];
            }

            $body = $response->getBody()->buffer();
            $services = json_decode($body, true);

            $instances = [];
            foreach ($services as $service) {
                $instances[] = new ServiceInstance(
                    id: $service['Service']['ID'],
                    serviceName: $service['Service']['Service'],
                    host: $service['Service']['Address'] ?: $service['Node']['Address'],
                    port: $service['Service']['Port'],
                    metadata: $service['Service']['Meta'] ?? [],
                    healthy: true,
                    weight: $service['Service']['Weights']['Passing'] ?? 100,
                    zone: $service['Node']['Datacenter'] ?? null,
                );
            }

            return $instances;
        });
    }

    /**
     * Watch for service changes
     */
    public function watch(string $serviceName, callable $callback): Future
    {
        return async(function () use ($serviceName, $callback) {
            $index = 0;

            while (true) {
                $url = "{$this->consulUrl}/v1/health/service/{$serviceName}?passing=true&index={$index}&wait=30s";
                
                $request = new Request($url);
                
                if ($this->config['token']) {
                    $request->setHeader('X-Consul-Token', $this->config['token']);
                }

                try {
                    $response = $this->httpClient->request($request);
                    
                    $newIndex = (int)($response->getHeader('X-Consul-Index') ?? 0);
                    
                    if ($newIndex > $index) {
                        $index = $newIndex;
                        $instances = $this->discover($serviceName)->await();
                        $callback('update', $instances);
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('Error watching Consul service', [
                        'service' => $serviceName,
                        'error' => $e->getMessage(),
                    ]);
                    
                    // Wait before retrying
                    \Amp\delay(5);
                }
            }
        });
    }

    /**
     * Get all registered services
     */
    public function getServices(): Future
    {
        return async(function () {
            $request = new Request("{$this->consulUrl}/v1/catalog/services");
            
            if ($this->config['token']) {
                $request->setHeader('X-Consul-Token', $this->config['token']);
            }

            $response = $this->httpClient->request($request);
            
            if ($response->getStatus() !== 200) {
                return [];
            }

            $body = $response->getBody()->buffer();
            $services = json_decode($body, true);

            return array_keys($services);
        });
    }

    /**
     * Health check for a service instance
     */
    public function healthCheck(string $serviceName, string $instanceId): Future
    {
        return async(function () use ($serviceName, $instanceId) {
            $request = new Request("{$this->consulUrl}/v1/health/checks/{$serviceName}");
            
            if ($this->config['token']) {
                $request->setHeader('X-Consul-Token', $this->config['token']);
            }

            $response = $this->httpClient->request($request);
            
            if ($response->getStatus() !== 200) {
                return false;
            }

            $body = $response->getBody()->buffer();
            $checks = json_decode($body, true);

            foreach ($checks as $check) {
                if ($check['ServiceID'] === $instanceId) {
                    return $check['Status'] === 'passing';
                }
            }

            return false;
        });
    }
}
