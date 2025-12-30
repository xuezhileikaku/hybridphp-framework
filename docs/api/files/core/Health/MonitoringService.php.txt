<?php

declare(strict_types=1);

namespace HybridPHP\Core\Health;

use HybridPHP\Core\Application;
use Amp\Future;
use Psr\Log\LoggerInterface;
use function Amp\async;
use function Amp\delay;

/**
 * Monitoring service for health checks and metrics
 */
class MonitoringService
{
    private HealthCheckManager $healthCheckManager;
    private Application $application;
    private ?LoggerInterface $logger;
    private array $config;
    private array $metrics = [];
    private array $alerts = [];
    private bool $running = false;

    public function __construct(
        HealthCheckManager $healthCheckManager,
        Application $application,
        ?LoggerInterface $logger = null,
        array $config = []
    ) {
        $this->healthCheckManager = $healthCheckManager;
        $this->application = $application;
        $this->logger = $logger;
        $this->config = array_merge([
            'check_interval' => 30,
            'alert_thresholds' => [
                'response_time' => 5.0,
                'error_rate' => 0.1,
                'memory_usage' => 0.9,
            ],
            'prometheus_enabled' => true,
            'elk_enabled' => true,
            'alert_enabled' => true,
        ], $config);
    }

    public function start(): Future
    {
        return async(function () {
            if ($this->running) {
                return;
            }

            $this->running = true;

            if ($this->logger) {
                $this->logger->info('Monitoring service started', [
                    'check_interval' => $this->config['check_interval'],
                ]);
            }

            $this->application->runCoroutine(
                function () {
                    $this->runPeriodicHealthChecks()->await();
                },
                'health_monitoring'
            );

            $this->application->runCoroutine(
                function () {
                    $this->runMetricsCollection()->await();
                },
                'metrics_collection'
            );
        });
    }

    public function stop(): void
    {
        $this->running = false;

        if ($this->logger) {
            $this->logger->info('Monitoring service stopped');
        }
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getAlerts(): array
    {
        return $this->alerts;
    }

    public function getPrometheusMetrics(): Future
    {
        return async(function () {
            $report = $this->healthCheckManager->checkAll()->await();
            return $report->toPrometheusFormat();
        });
    }

    public function getElkMetrics(): Future
    {
        return async(function () {
            $report = $this->healthCheckManager->checkAll()->await();
            return $report->toElkFormat();
        });
    }

    public function addMetric(string $name, $value, array $labels = []): void
    {
        $this->metrics[$name] = [
            'value' => $value,
            'labels' => $labels,
            'timestamp' => time(),
        ];
    }

    public function addAlert(string $name, string $message, string $severity = 'warning', array $data = []): void
    {
        $this->alerts[$name] = [
            'message' => $message,
            'severity' => $severity,
            'data' => $data,
            'timestamp' => time(),
        ];

        if ($this->logger) {
            $this->logger->warning("Alert triggered: {$name}", [
                'message' => $message,
                'severity' => $severity,
                'data' => $data,
            ]);
        }
    }

    public function clearAlert(string $name): void
    {
        if (isset($this->alerts[$name])) {
            unset($this->alerts[$name]);

            if ($this->logger) {
                $this->logger->info("Alert cleared: {$name}");
            }
        }
    }

    private function runPeriodicHealthChecks(): Future
    {
        return async(function () {
            while ($this->running) {
                try {
                    $report = $this->healthCheckManager->checkAll()->await();

                    $this->updateHealthMetrics($report);

                    if ($this->config['alert_enabled']) {
                        $this->checkAlerts($report);
                    }

                    if ($this->config['elk_enabled'] && $this->logger) {
                        $elkData = $report->toElkFormat();
                        $this->logger->info('Health check report', $elkData);
                    }

                } catch (\Throwable $e) {
                    if ($this->logger) {
                        $this->logger->error('Health check monitoring failed', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }

                delay($this->config['check_interval']);
            }
        });
    }

    private function runMetricsCollection(): Future
    {
        return async(function () {
            while ($this->running) {
                try {
                    $this->collectSystemMetrics();
                    $this->collectApplicationMetrics();

                } catch (\Throwable $e) {
                    if ($this->logger) {
                        $this->logger->error('Metrics collection failed', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                delay(10);
            }
        });
    }

    private function updateHealthMetrics(HealthCheckReport $report): void
    {
        $summary = $report->getSummary();

        $this->addMetric('health_status', $report->isHealthy() ? 1 : 0);
        $this->addMetric('health_checks_total', $summary['total']);
        $this->addMetric('health_checks_healthy', $summary['healthy']);
        $this->addMetric('health_checks_unhealthy', $summary['unhealthy']);
        $this->addMetric('health_checks_warning', $summary['warning']);
        $this->addMetric('health_check_duration', $report->getTotalTime());

        foreach ($report->getResults() as $name => $result) {
            $this->addMetric("health_check_{$name}_status", $result->isHealthy() ? 1 : 0);
            $this->addMetric("health_check_{$name}_response_time", $result->getResponseTime());
        }
    }

    private function checkAlerts(HealthCheckReport $report): void
    {
        if (!$report->isHealthy()) {
            $unhealthyChecks = array_keys($report->getUnhealthyResults());
            $this->addAlert(
                'system_unhealthy',
                'System health check failed',
                'critical',
                ['failed_checks' => $unhealthyChecks]
            );
        } else {
            $this->clearAlert('system_unhealthy');
        }

        foreach ($report->getResults() as $name => $result) {
            $responseTime = $result->getResponseTime();
            $threshold = $this->config['alert_thresholds']['response_time'];

            if ($responseTime > $threshold) {
                $this->addAlert(
                    "slow_health_check_{$name}",
                    "Health check '{$name}' is slow",
                    'warning',
                    ['response_time' => $responseTime, 'threshold' => $threshold]
                );
            } else {
                $this->clearAlert("slow_health_check_{$name}");
            }
        }
    }

    private function collectSystemMetrics(): void
    {
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));

        $this->addMetric('memory_usage_bytes', $memoryUsage);
        $this->addMetric('memory_peak_bytes', $memoryPeak);
        $this->addMetric('memory_limit_bytes', $memoryLimit);

        if ($memoryLimit > 0) {
            $memoryUsagePercent = $memoryUsage / $memoryLimit;
            $this->addMetric('memory_usage_percent', $memoryUsagePercent);

            if ($memoryUsagePercent > $this->config['alert_thresholds']['memory_usage']) {
                $this->addAlert(
                    'high_memory_usage',
                    'High memory usage detected',
                    'warning',
                    ['usage_percent' => $memoryUsagePercent * 100]
                );
            } else {
                $this->clearAlert('high_memory_usage');
            }
        }

        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $this->addMetric('cpu_load_1m', $load[0]);
            $this->addMetric('cpu_load_5m', $load[1]);
            $this->addMetric('cpu_load_15m', $load[2]);
        }

        $storagePath = __DIR__ . '/../../storage';
        if (is_dir($storagePath)) {
            $diskFree = disk_free_space($storagePath);
            $diskTotal = disk_total_space($storagePath);

            if ($diskFree !== false && $diskTotal !== false) {
                $this->addMetric('disk_free_bytes', $diskFree);
                $this->addMetric('disk_total_bytes', $diskTotal);
                $this->addMetric('disk_usage_percent', ($diskTotal - $diskFree) / $diskTotal);
            }
        }
    }

    private function collectApplicationMetrics(): void
    {
        $uptime = time() - ($_SERVER['REQUEST_TIME'] ?? time());
        $this->addMetric('app_uptime_seconds', $uptime);

        $coroutines = $this->application->getRunningCoroutines();
        $this->addMetric('app_coroutines_count', count($coroutines));

        $this->addMetric('app_running', $this->application->isRunning() ? 1 : 0);
        $this->addMetric('app_shutting_down', $this->application->isShuttingDown() ? 1 : 0);
    }

    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return 0;
        }

        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}
