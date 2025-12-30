<?php

declare(strict_types=1);

namespace HybridPHP\Core\Health;

use HybridPHP\Core\Health\Checks\ApplicationHealthCheck;
use HybridPHP\Core\Health\Checks\DatabaseHealthCheck;
use HybridPHP\Core\Health\Checks\CacheHealthCheck;
use HybridPHP\Core\Health\Checks\ExternalServiceHealthCheck;
use HybridPHP\Core\Container;
use HybridPHP\Core\Application;
use HybridPHP\Core\Database\DatabaseInterface;
use HybridPHP\Core\Cache\CacheInterface;
use Psr\Log\LoggerInterface;
use Amp\Http\Client\HttpClient;

/**
 * Health check service provider
 */
class HealthServiceProvider
{
    private Container $container;
    private array $config;

    public function __construct(Container $container, array $config = [])
    {
        $this->container = $container;
        $this->config = array_merge([
            'enabled' => true,
            'checks' => [
                'application' => true,
                'database' => true,
                'cache' => true,
                'external_services' => [],
            ],
            'monitoring' => [
                'enabled' => true,
                'check_interval' => 30,
                'prometheus_enabled' => true,
                'elk_enabled' => true,
                'alert_enabled' => true,
            ],
            'thresholds' => [
                'response_time' => 5.0,
                'error_rate' => 0.1,
                'memory_usage' => 0.9,
            ],
        ], $config);
    }

    /**
     * Register health check services
     */
    public function register(): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        // Register health check manager
        $this->container->singleton(HealthCheckManager::class, function ($container) {
            $logger = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;
            return new HealthCheckManager($logger, $this->config);
        });

        // Register monitoring service
        if ($this->config['monitoring']['enabled']) {
            $this->container->singleton(MonitoringService::class, function ($container) {
                $healthCheckManager = $container->get(HealthCheckManager::class);
                $application = $container->get(Application::class);
                $logger = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;
                
                return new MonitoringService(
                    $healthCheckManager,
                    $application,
                    $logger,
                    $this->config['monitoring']
                );
            });
        }
    }

    /**
     * Boot health check services and register default checks
     */
    public function boot(): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        $healthCheckManager = $this->container->get(HealthCheckManager::class);
        $logger = $this->container->has(LoggerInterface::class) ? $this->container->get(LoggerInterface::class) : null;

        // Register application health check
        if ($this->config['checks']['application']) {
            $application = $this->container->get(Application::class);
            $healthCheckManager->register(new ApplicationHealthCheck($application, $logger));
        }

        // Register database health check
        if ($this->config['checks']['database'] && $this->container->has(DatabaseInterface::class)) {
            $database = $this->container->get(DatabaseInterface::class);
            $healthCheckManager->register(new DatabaseHealthCheck($database, $logger));
        }

        // Register cache health check
        if ($this->config['checks']['cache'] && $this->container->has(CacheInterface::class)) {
            $cache = $this->container->get(CacheInterface::class);
            $healthCheckManager->register(new CacheHealthCheck($cache, $logger));
        }

        // Register external service health checks
        if (!empty($this->config['checks']['external_services'])) {
            $httpClient = $this->container->has(HttpClient::class) ? 
                $this->container->get(HttpClient::class) : 
                new HttpClient();

            foreach ($this->config['checks']['external_services'] as $name => $serviceConfig) {
                $healthCheck = new ExternalServiceHealthCheck(
                    $name,
                    $serviceConfig['url'],
                    $httpClient,
                    $serviceConfig['headers'] ?? [],
                    $serviceConfig['expected_status'] ?? 200,
                    $serviceConfig['expected_content'] ?? null,
                    $logger,
                    $serviceConfig['timeout'] ?? 10,
                    $serviceConfig['critical'] ?? false
                );
                
                $healthCheckManager->register($healthCheck);
            }
        }

        // Start monitoring service if enabled
        if ($this->config['monitoring']['enabled'] && $this->container->has(MonitoringService::class)) {
            $monitoringService = $this->container->get(MonitoringService::class);
            $monitoringService->start();
        }
    }

    /**
     * Get default configuration
     */
    public static function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'checks' => [
                'application' => true,
                'database' => true,
                'cache' => true,
                'external_services' => [
                    // Example external service check
                    // 'api_service' => [
                    //     'url' => 'https://api.example.com/health',
                    //     'timeout' => 5,
                    //     'critical' => false,
                    //     'expected_status' => 200,
                    //     'headers' => ['Authorization' => 'Bearer token']
                    // ]
                ],
            ],
            'monitoring' => [
                'enabled' => true,
                'check_interval' => 30, // seconds
                'prometheus_enabled' => true,
                'elk_enabled' => true,
                'alert_enabled' => true,
                'alert_thresholds' => [
                    'response_time' => 5.0, // seconds
                    'error_rate' => 0.1, // 10%
                    'memory_usage' => 0.9, // 90%
                ],
            ],
        ];
    }

    /**
     * Create from configuration file
     */
    public static function fromConfig(Container $container, string $configPath): self
    {
        $config = [];
        
        if (file_exists($configPath)) {
            $config = require $configPath;
        }
        
        return new self($container, $config);
    }
}