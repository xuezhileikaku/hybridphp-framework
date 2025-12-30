<?php

declare(strict_types=1);

namespace HybridPHP\Core\Monitoring;

use HybridPHP\Core\Application;
use HybridPHP\Core\Container;
use Psr\Log\LoggerInterface;

/**
 * Monitoring service provider
 */
class MonitoringServiceProvider
{
    private Container $container;
    private array $config;

    public function __construct(Container $container, array $config = [])
    {
        $this->container = $container;
        $this->config = array_merge([
            'enabled' => true,
            'metrics' => [
                'collection_interval' => 10,
                'histogram_buckets' => [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0],
                'max_metrics' => 10000,
            ],
            'alerts' => [
                'processing_interval' => 10,
                'alert_retention' => 3600,
                'max_alerts' => 1000,
                'cooldown_period' => 300,
            ],
            'performance' => [
                'monitoring_interval' => 5,
                'request_timeout_threshold' => 30.0,
                'memory_threshold' => 0.9,
                'cpu_threshold' => 0.8,
                'coroutine_threshold' => 1000,
            ],
            'dashboard' => [
                'auth_enabled' => true,
                'auth_token' => null,
                'refresh_interval' => 5000,
            ],
            'notifications' => [
                'log' => ['enabled' => true],
                'email' => ['enabled' => false],
                'webhook' => ['enabled' => false, 'url' => null],
                'slack' => ['enabled' => false, 'webhook_url' => null],
            ],
        ], $config);
    }

    /**
     * Register monitoring services
     */
    public function register(): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        // Register metrics collector
        $this->container->singleton(MetricsCollector::class, function () {
            return new MetricsCollector(
                $this->container->get(LoggerInterface::class),
                $this->config['metrics']
            );
        });

        // Register alert manager
        $this->container->singleton(AlertManager::class, function () {
            $alertManager = new AlertManager(
                $this->container->get(LoggerInterface::class),
                $this->config['alerts']
            );

            // Set up notification handlers
            $this->setupNotificationHandlers($alertManager);

            // Set up default alert rules
            $this->setupDefaultAlertRules($alertManager);

            return $alertManager;
        });

        // Register performance monitor
        $this->container->singleton(PerformanceMonitor::class, function () {
            return new PerformanceMonitor(
                $this->container->get(MetricsCollector::class),
                $this->container->get(AlertManager::class),
                $this->container->get(Application::class),
                $this->container->get(LoggerInterface::class),
                $this->config['performance']
            );
        });

        // Register monitoring dashboard
        $this->container->singleton(MonitoringDashboard::class, function () {
            return new MonitoringDashboard(
                $this->container->get(PerformanceMonitor::class),
                $this->container->get(AlertManager::class),
                $this->container->get(Application::class),
                $this->container->get(LoggerInterface::class),
                $this->config['dashboard']
            );
        });
    }

    /**
     * Boot monitoring services
     */
    public function boot(): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        $application = $this->container->get(Application::class);
        $performanceMonitor = $this->container->get(PerformanceMonitor::class);
        $alertManager = $this->container->get(AlertManager::class);

        // Start monitoring services
        $application->runCoroutine(function () use ($performanceMonitor) {
            $performanceMonitor->start()->await();
        }, 'performance_monitor');

        $application->runCoroutine(function () use ($alertManager) {
            $alertManager->start()->await();
        }, 'alert_manager');

        // Register shutdown handlers
        $application->onShutdown(function () use ($performanceMonitor, $alertManager) {
            $performanceMonitor->stop();
            $alertManager->stop();
        });
    }

    /**
     * Set up notification handlers
     */
    private function setupNotificationHandlers(AlertManager $alertManager): void
    {
        // Log handler
        if ($this->config['notifications']['log']['enabled']) {
            $logger = $this->container->get(LoggerInterface::class);
            $alertManager->addNotifier('log', NotificationHandlers::logHandler($logger));
        }

        // Email handler
        if ($this->config['notifications']['email']['enabled']) {
            $alertManager->addNotifier('email', NotificationHandlers::emailHandler(
                $this->config['notifications']['email']
            ));
        }

        // Webhook handler
        if ($this->config['notifications']['webhook']['enabled'] && 
            $this->config['notifications']['webhook']['url']) {
            $alertManager->addNotifier('webhook', NotificationHandlers::webhookHandler(
                $this->config['notifications']['webhook']['url'],
                $this->config['notifications']['webhook']['headers'] ?? []
            ));
        }

        // Slack handler
        if ($this->config['notifications']['slack']['enabled'] && 
            $this->config['notifications']['slack']['webhook_url']) {
            $alertManager->addNotifier('slack', NotificationHandlers::slackHandler(
                $this->config['notifications']['slack']['webhook_url']
            ));
        }
    }

    /**
     * Set up default alert rules
     */
    private function setupDefaultAlertRules(AlertManager $alertManager): void
    {
        $metricsCollector = $this->container->get(MetricsCollector::class);

        // High memory usage rule
        $alertManager->addRule('high_memory_usage', function () use ($metricsCollector) {
            $metrics = $metricsCollector->getMetrics();
            foreach ($metrics['gauges'] as $gauge) {
                if ($gauge['name'] === 'php_memory_usage_ratio' && 
                    $gauge['value'] > $this->config['performance']['memory_threshold']) {
                    return [
                        'usage_ratio' => $gauge['value'],
                        'threshold' => $this->config['performance']['memory_threshold'],
                    ];
                }
            }
            return false;
        }, ['severity' => 'warning']);

        // High CPU load rule
        $alertManager->addRule('high_cpu_load', function () use ($metricsCollector) {
            $metrics = $metricsCollector->getMetrics();
            foreach ($metrics['gauges'] as $gauge) {
                if ($gauge['name'] === 'system_load_1m' && 
                    $gauge['value'] > $this->config['performance']['cpu_threshold']) {
                    return [
                        'load' => $gauge['value'],
                        'threshold' => $this->config['performance']['cpu_threshold'],
                    ];
                }
            }
            return false;
        }, ['severity' => 'warning']);

        // High coroutine count rule
        $alertManager->addRule('high_coroutine_count', function () use ($metricsCollector) {
            $metrics = $metricsCollector->getMetrics();
            foreach ($metrics['gauges'] as $gauge) {
                if ($gauge['name'] === 'app_coroutines_active' && 
                    $gauge['value'] > $this->config['performance']['coroutine_threshold']) {
                    return [
                        'count' => $gauge['value'],
                        'threshold' => $this->config['performance']['coroutine_threshold'],
                    ];
                }
            }
            return false;
        }, ['severity' => 'warning']);

        // Disk space rule
        $alertManager->addRule('low_disk_space', function () use ($metricsCollector) {
            $metrics = $metricsCollector->getMetrics();
            foreach ($metrics['gauges'] as $gauge) {
                if ($gauge['name'] === 'disk_usage_ratio' && $gauge['value'] > 0.9) {
                    return [
                        'usage_ratio' => $gauge['value'],
                        'threshold' => 0.9,
                    ];
                }
            }
            return false;
        }, ['severity' => 'critical']);
    }

    /**
     * Get monitoring middleware
     */
    public function getMonitoringMiddleware(): callable
    {
        return function ($request, $handler) {
            $performanceMonitor = $this->container->get(PerformanceMonitor::class);
            $requestId = uniqid('req_', true);
            
            // Record request start
            $performanceMonitor->recordRequestStart(
                $requestId,
                $request->getMethod(),
                $request->getUri()->getPath()
            );

            try {
                $response = $handler->handle($request);
                
                // Record successful request
                $performanceMonitor->recordRequestEnd(
                    $requestId,
                    $response->getStatus(),
                    strlen($response->getBody())
                );
                
                return $response;
            } catch (\Throwable $e) {
                // Record failed request
                $performanceMonitor->recordRequestEnd($requestId, 500);
                throw $e;
            }
        };
    }
}