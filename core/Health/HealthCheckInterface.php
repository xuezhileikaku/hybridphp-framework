<?php

declare(strict_types=1);

namespace HybridPHP\Core\Health;

use Amp\Future;

/**
 * Health check interface for system components
 */
interface HealthCheckInterface
{
    /**
     * Perform health check
     * 
     * @return Future<HealthCheckResult>
     */
    public function check(): Future;

    /**
     * Get health check name
     */
    public function getName(): string;

    /**
     * Get health check timeout in seconds
     */
    public function getTimeout(): int;

    /**
     * Check if this health check is critical
     */
    public function isCritical(): bool;
}