<?php

declare(strict_types=1);

namespace HybridPHP\Core\Health\Checks;

use HybridPHP\Core\Health\AbstractHealthCheck;
use HybridPHP\Core\Health\HealthCheckResult;
use HybridPHP\Core\Application;
use Amp\Future;
use Psr\Log\LoggerInterface;
use function Amp\async;

/**
 * Application health check
 */
class ApplicationHealthCheck extends AbstractHealthCheck
{
    private Application $application;

    public function __construct(
        Application $application,
        ?LoggerInterface $logger = null,
        int $timeout = 2,
        bool $critical = true
    ) {
        parent::__construct('application', $timeout, $critical, $logger);
        $this->application = $application;
    }

    protected function performCheck(): Future
    {
        return async(function () {
            try {
                // Check if application is running
                if (!$this->application->isRunning()) {
                    return HealthCheckResult::unhealthy(
                        $this->name,
                        'Application is not running'
                    );
                }

                // Check if application is shutting down
                if ($this->application->isShuttingDown()) {
                    return HealthCheckResult::warning(
                        $this->name,
                        'Application is shutting down'
                    );
                }

                // Get memory usage
                $memoryUsage = memory_get_usage(true);
                $memoryPeak = memory_get_peak_usage(true);
                $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));

                $data = [
                    'version' => $this->application->getVersion(),
                    'environment' => $this->application->getEnvironment(),
                    'debug' => $this->application->isDebug(),
                    'memory' => [
                        'usage' => $memoryUsage,
                        'peak' => $memoryPeak,
                        'limit' => $memoryLimit,
                        'usage_percent' => $memoryLimit > 0 ? ($memoryUsage / $memoryLimit) * 100 : 0,
                    ],
                    'coroutines' => $this->application->getRunningCoroutines(),
                    'uptime' => time() - ($_SERVER['REQUEST_TIME'] ?? time()),
                ];

                // Check memory usage
                if ($memoryLimit > 0 && ($memoryUsage / $memoryLimit) > 0.9) {
                    return HealthCheckResult::warning(
                        $this->name,
                        'High memory usage detected',
                        $data
                    );
                }

                // Check for too many running coroutines
                if (count($data['coroutines']) > 1000) {
                    return HealthCheckResult::warning(
                        $this->name,
                        'High number of running coroutines',
                        $data
                    );
                }

                return HealthCheckResult::healthy(
                    $this->name,
                    'Application is healthy',
                    $data
                );

            } catch (\Throwable $e) {
                return HealthCheckResult::unhealthy(
                    $this->name,
                    'Application health check failed: ' . $e->getMessage(),
                    [],
                    0.0,
                    $e
                );
            }
        });
    }

    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return 0; // No limit
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