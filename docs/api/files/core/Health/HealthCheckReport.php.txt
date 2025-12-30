<?php

declare(strict_types=1);

namespace HybridPHP\Core\Health;

/**
 * Health check report
 */
class HealthCheckReport
{
    /** @var HealthCheckResult[] */
    private array $results;
    private int $timestamp;
    private float $totalTime;

    /**
     * @param HealthCheckResult[] $results
     */
    public function __construct(array $results, int $timestamp, float $totalTime = 0.0)
    {
        $this->results = $results;
        $this->timestamp = $timestamp;
        $this->totalTime = $totalTime;
    }

    /**
     * Get all results
     * 
     * @return HealthCheckResult[]
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Get result by name
     */
    public function getResult(string $name): ?HealthCheckResult
    {
        return $this->results[$name] ?? null;
    }

    /**
     * Get timestamp
     */
    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    /**
     * Get total execution time
     */
    public function getTotalTime(): float
    {
        return $this->totalTime;
    }

    /**
     * Check if all health checks are healthy
     */
    public function isHealthy(): bool
    {
        foreach ($this->results as $result) {
            if (!$result->isHealthy()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get overall status
     */
    public function getOverallStatus(): string
    {
        $hasUnhealthy = false;
        $hasWarning = false;

        foreach ($this->results as $result) {
            if ($result->isUnhealthy()) {
                $hasUnhealthy = true;
            } elseif ($result->isWarning()) {
                $hasWarning = true;
            }
        }

        if ($hasUnhealthy) {
            return HealthCheckResult::STATUS_UNHEALTHY;
        } elseif ($hasWarning) {
            return HealthCheckResult::STATUS_WARNING;
        }

        return HealthCheckResult::STATUS_HEALTHY;
    }

    /**
     * Get healthy results
     * 
     * @return HealthCheckResult[]
     */
    public function getHealthyResults(): array
    {
        return array_filter($this->results, fn($result) => $result->isHealthy());
    }

    /**
     * Get unhealthy results
     * 
     * @return HealthCheckResult[]
     */
    public function getUnhealthyResults(): array
    {
        return array_filter($this->results, fn($result) => $result->isUnhealthy());
    }

    /**
     * Get warning results
     * 
     * @return HealthCheckResult[]
     */
    public function getWarningResults(): array
    {
        return array_filter($this->results, fn($result) => $result->isWarning());
    }

    /**
     * Get summary statistics
     */
    public function getSummary(): array
    {
        $total = count($this->results);
        $healthy = count($this->getHealthyResults());
        $unhealthy = count($this->getUnhealthyResults());
        $warning = count($this->getWarningResults());

        return [
            'total' => $total,
            'healthy' => $healthy,
            'unhealthy' => $unhealthy,
            'warning' => $warning,
            'overall_status' => $this->getOverallStatus(),
            'timestamp' => $this->timestamp,
            'total_time' => $this->totalTime,
        ];
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        $results = [];
        foreach ($this->results as $name => $result) {
            $results[$name] = $result->toArray();
        }

        return [
            'status' => $this->getOverallStatus(),
            'timestamp' => $this->timestamp,
            'total_time' => $this->totalTime,
            'summary' => $this->getSummary(),
            'checks' => $results,
        ];
    }

    /**
     * Convert to Prometheus format
     */
    public function toPrometheusFormat(): string
    {
        $metrics = [];
        
        // Overall health metric
        $overallHealthy = $this->isHealthy() ? 1 : 0;
        $metrics[] = "# HELP hybridphp_health_status Overall application health status (1 = healthy, 0 = unhealthy)";
        $metrics[] = "# TYPE hybridphp_health_status gauge";
        $metrics[] = "hybridphp_health_status {$overallHealthy}";
        
        // Individual health check metrics
        $metrics[] = "# HELP hybridphp_health_check_status Individual health check status (1 = healthy, 0 = unhealthy)";
        $metrics[] = "# TYPE hybridphp_health_check_status gauge";
        
        foreach ($this->results as $name => $result) {
            $status = $result->isHealthy() ? 1 : 0;
            $metrics[] = "hybridphp_health_check_status{check=\"{$name}\"} {$status}";
        }
        
        // Response time metrics
        $metrics[] = "# HELP hybridphp_health_check_response_time Health check response time in seconds";
        $metrics[] = "# TYPE hybridphp_health_check_response_time gauge";
        
        foreach ($this->results as $name => $result) {
            $responseTime = $result->getResponseTime();
            $metrics[] = "hybridphp_health_check_response_time{check=\"{$name}\"} {$responseTime}";
        }
        
        // Total execution time
        $metrics[] = "# HELP hybridphp_health_check_total_time Total health check execution time in seconds";
        $metrics[] = "# TYPE hybridphp_health_check_total_time gauge";
        $metrics[] = "hybridphp_health_check_total_time {$this->totalTime}";
        
        return implode("\n", $metrics) . "\n";
    }

    /**
     * Convert to ELK/JSON format
     */
    public function toElkFormat(): array
    {
        $elkData = [
            '@timestamp' => date('c', $this->timestamp),
            'service' => 'hybridphp',
            'type' => 'health_check',
            'level' => $this->isHealthy() ? 'INFO' : 'ERROR',
            'message' => 'Health check report',
            'health' => [
                'overall_status' => $this->getOverallStatus(),
                'is_healthy' => $this->isHealthy(),
                'total_time' => $this->totalTime,
                'summary' => $this->getSummary(),
            ],
            'checks' => [],
        ];

        foreach ($this->results as $name => $result) {
            $elkData['checks'][$name] = [
                'status' => $result->getStatus(),
                'message' => $result->getMessage(),
                'response_time' => $result->getResponseTime(),
                'data' => $result->getData(),
                'timestamp' => $result->getTimestamp(),
            ];
            
            if ($result->getException()) {
                $elkData['checks'][$name]['error'] = [
                    'message' => $result->getException()->getMessage(),
                    'class' => get_class($result->getException()),
                    'file' => $result->getException()->getFile(),
                    'line' => $result->getException()->getLine(),
                ];
            }
        }

        return $elkData;
    }
}