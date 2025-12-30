<?php

declare(strict_types=1);

namespace HybridPHP\Core\Health;

use Amp\Future;
use Amp\TimeoutException;
use Psr\Log\LoggerInterface;
use function Amp\async;
use function Amp\delay;

/**
 * Health check manager
 */
class HealthCheckManager
{
    /** @var HealthCheckInterface[] */
    private array $healthChecks = [];
    
    private ?LoggerInterface $logger;
    private array $config;
    private array $lastResults = [];

    public function __construct(?LoggerInterface $logger = null, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge([
            'parallel_execution' => true,
            'fail_fast' => false,
            'cache_results' => true,
            'cache_ttl' => 30,
        ], $config);
    }

    /**
     * Register a health check
     */
    public function register(HealthCheckInterface $healthCheck): void
    {
        $this->healthChecks[$healthCheck->getName()] = $healthCheck;
        
        if ($this->logger) {
            $this->logger->info("Health check registered: {$healthCheck->getName()}", [
                'critical' => $healthCheck->isCritical(),
                'timeout' => $healthCheck->getTimeout(),
            ]);
        }
    }

    /**
     * Unregister a health check
     */
    public function unregister(string $name): bool
    {
        if (isset($this->healthChecks[$name])) {
            unset($this->healthChecks[$name]);
            unset($this->lastResults[$name]);
            
            if ($this->logger) {
                $this->logger->info("Health check unregistered: {$name}");
            }
            
            return true;
        }
        
        return false;
    }

    /**
     * Run all health checks
     */
    public function checkAll(): Future
    {
        return async(function () {
            if (empty($this->healthChecks)) {
                return new HealthCheckReport([], time());
            }

            if ($this->logger) {
                $this->logger->info("Running health checks", [
                    'count' => count($this->healthChecks),
                    'parallel' => $this->config['parallel_execution'],
                ]);
            }

            $startTime = microtime(true);
            
            if ($this->config['parallel_execution']) {
                $results = $this->runHealthChecksParallel()->await();
            } else {
                $results = $this->runHealthChecksSequential()->await();
            }

            $totalTime = microtime(true) - $startTime;
            
            if ($this->config['cache_results']) {
                $this->lastResults = $results;
            }

            if ($this->logger) {
                $this->logger->info("Health checks completed", [
                    'total_time' => $totalTime,
                    'results_count' => count($results),
                ]);
            }

            return new HealthCheckReport($results, time(), $totalTime);
        });
    }

    /**
     * Run a specific health check
     */
    public function check(string $name): Future
    {
        return async(function () use ($name) {
            if (!isset($this->healthChecks[$name])) {
                throw new \InvalidArgumentException("Health check not found: {$name}");
            }

            $healthCheck = $this->healthChecks[$name];
            
            try {
                $result = $this->executeHealthCheckWithTimeout($healthCheck)->await();
                
                if ($this->config['cache_results']) {
                    $this->lastResults[$name] = $result;
                }
                
                return $result;
                
            } catch (TimeoutException $e) {
                $result = HealthCheckResult::unhealthy(
                    $name,
                    "Health check timed out after {$healthCheck->getTimeout()} seconds",
                    [],
                    $healthCheck->getTimeout(),
                    $e
                );
                
                if ($this->config['cache_results']) {
                    $this->lastResults[$name] = $result;
                }
                
                return $result;
            }
        });
    }

    /**
     * Get cached results
     */
    public function getCachedResults(): array
    {
        return $this->lastResults;
    }

    /**
     * Get registered health checks
     */
    public function getHealthChecks(): array
    {
        return array_keys($this->healthChecks);
    }

    /**
     * Check if a health check is registered
     */
    public function hasHealthCheck(string $name): bool
    {
        return isset($this->healthChecks[$name]);
    }

    /**
     * Run health checks in parallel
     */
    private function runHealthChecksParallel(): Future
    {
        return async(function () {
            $futures = [];
            
            foreach ($this->healthChecks as $name => $healthCheck) {
                $futures[$name] = $this->executeHealthCheckWithTimeout($healthCheck);
            }

            $results = [];
            
            foreach ($futures as $name => $future) {
                try {
                    $results[$name] = $future->await();
                } catch (\Throwable $e) {
                    $results[$name] = HealthCheckResult::unhealthy(
                        $name,
                        'Health check failed: ' . $e->getMessage(),
                        [],
                        0.0,
                        $e
                    );
                }
                
                if ($this->config['fail_fast'] && 
                    $this->healthChecks[$name]->isCritical() && 
                    !$results[$name]->isHealthy()) {
                    
                    if ($this->logger) {
                        $this->logger->warning("Critical health check failed, stopping execution", [
                            'failed_check' => $name,
                        ]);
                    }
                    
                    break;
                }
            }

            return $results;
        });
    }

    /**
     * Run health checks sequentially
     */
    private function runHealthChecksSequential(): Future
    {
        return async(function () {
            $results = [];
            
            foreach ($this->healthChecks as $name => $healthCheck) {
                try {
                    $results[$name] = $this->executeHealthCheckWithTimeout($healthCheck)->await();
                } catch (\Throwable $e) {
                    $results[$name] = HealthCheckResult::unhealthy(
                        $name,
                        'Health check failed: ' . $e->getMessage(),
                        [],
                        0.0,
                        $e
                    );
                }
                
                if ($this->config['fail_fast'] && 
                    $healthCheck->isCritical() && 
                    !$results[$name]->isHealthy()) {
                    
                    if ($this->logger) {
                        $this->logger->warning("Critical health check failed, stopping execution", [
                            'failed_check' => $name,
                        ]);
                    }
                    
                    break;
                }
            }

            return $results;
        });
    }

    /**
     * Execute health check with timeout
     */
    private function executeHealthCheckWithTimeout(HealthCheckInterface $healthCheck): Future
    {
        return async(function () use ($healthCheck) {
            try {
                $checkFuture = $healthCheck->check();
                return $checkFuture->await();
                
            } catch (TimeoutException $e) {
                throw $e;
            } catch (\Throwable $e) {
                return HealthCheckResult::unhealthy(
                    $healthCheck->getName(),
                    'Health check execution failed: ' . $e->getMessage(),
                    [],
                    0.0,
                    $e
                );
            }
        });
    }
}
