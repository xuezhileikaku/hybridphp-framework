<?php

declare(strict_types=1);

namespace HybridPHP\Core\Health;

use Amp\Future;
use Psr\Log\LoggerInterface;
use function Amp\async;

/**
 * Abstract base class for health checks
 */
abstract class AbstractHealthCheck implements HealthCheckInterface
{
    protected string $name;
    protected int $timeout;
    protected bool $critical;
    protected ?LoggerInterface $logger;

    public function __construct(
        string $name,
        int $timeout = 5,
        bool $critical = false,
        ?LoggerInterface $logger = null
    ) {
        $this->name = $name;
        $this->timeout = $timeout;
        $this->critical = $critical;
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function isCritical(): bool
    {
        return $this->critical;
    }

    public function check(): Future
    {
        return async(function () {
            $startTime = microtime(true);

            try {
                if ($this->logger) {
                    $this->logger->debug("Starting health check: {$this->name}");
                }

                $result = $this->performCheck()->await();

                $responseTime = microtime(true) - $startTime;

                if ($this->logger) {
                    $this->logger->debug("Health check completed: {$this->name}", [
                        'status' => $result->getStatus(),
                        'response_time' => $responseTime,
                    ]);
                }

                return new HealthCheckResult(
                    $this->name,
                    $result->getStatus(),
                    $result->getMessage(),
                    $result->getData(),
                    $responseTime,
                    $result->getException()
                );

            } catch (\Throwable $e) {
                $responseTime = microtime(true) - $startTime;

                if ($this->logger) {
                    $this->logger->error("Health check failed: {$this->name}", [
                        'error' => $e->getMessage(),
                        'response_time' => $responseTime,
                    ]);
                }

                return HealthCheckResult::unhealthy(
                    $this->name,
                    "Health check failed: " . $e->getMessage(),
                    [],
                    $responseTime,
                    $e
                );
            }
        });
    }

    /**
     * Perform the actual health check
     * 
     * @return Future<HealthCheckResult>
     */
    abstract protected function performCheck(): Future;
}