<?php

declare(strict_types=1);

namespace HybridPHP\Core\Health;

/**
 * Health check result
 */
class HealthCheckResult
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_UNHEALTHY = 'unhealthy';
    public const STATUS_WARNING = 'warning';
    public const STATUS_UNKNOWN = 'unknown';

    private string $name;
    private string $status;
    private ?string $message;
    private array $data;
    private float $responseTime;
    private ?\Throwable $exception;
    private int $timestamp;

    public function __construct(
        string $name,
        string $status,
        ?string $message = null,
        array $data = [],
        float $responseTime = 0.0,
        ?\Throwable $exception = null
    ) {
        $this->name = $name;
        $this->status = $status;
        $this->message = $message;
        $this->data = $data;
        $this->responseTime = $responseTime;
        $this->exception = $exception;
        $this->timestamp = time();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getResponseTime(): float
    {
        return $this->responseTime;
    }

    public function getException(): ?\Throwable
    {
        return $this->exception;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_HEALTHY;
    }

    public function isUnhealthy(): bool
    {
        return $this->status === self::STATUS_UNHEALTHY;
    }

    public function isWarning(): bool
    {
        return $this->status === self::STATUS_WARNING;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'message' => $this->message,
            'data' => $this->data,
            'response_time' => $this->responseTime,
            'timestamp' => $this->timestamp,
            'error' => $this->exception ? $this->exception->getMessage() : null,
        ];
    }

    public static function healthy(string $name, ?string $message = null, array $data = [], float $responseTime = 0.0): self
    {
        return new self($name, self::STATUS_HEALTHY, $message, $data, $responseTime);
    }

    public static function unhealthy(string $name, ?string $message = null, array $data = [], float $responseTime = 0.0, ?\Throwable $exception = null): self
    {
        return new self($name, self::STATUS_UNHEALTHY, $message, $data, $responseTime, $exception);
    }

    public static function warning(string $name, ?string $message = null, array $data = [], float $responseTime = 0.0): self
    {
        return new self($name, self::STATUS_WARNING, $message, $data, $responseTime);
    }

    public static function unknown(string $name, ?string $message = null, array $data = [], float $responseTime = 0.0): self
    {
        return new self($name, self::STATUS_UNKNOWN, $message, $data, $responseTime);
    }
}