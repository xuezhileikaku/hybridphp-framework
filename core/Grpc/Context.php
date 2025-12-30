<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc;

/**
 * gRPC call context containing metadata and deadline
 */
class Context
{
    protected array $metadata;
    protected ?float $deadline;
    protected bool $cancelled = false;
    protected array $values = [];

    public function __construct(array $metadata = [], ?float $deadline = null)
    {
        $this->metadata = $metadata;
        $this->deadline = $deadline;
    }

    /**
     * Get all metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get a specific metadata value
     */
    public function getMetadataValue(string $key): ?string
    {
        return $this->metadata[$key] ?? null;
    }

    /**
     * Set metadata value
     */
    public function setMetadata(string $key, string $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * Get the deadline timestamp
     */
    public function getDeadline(): ?float
    {
        return $this->deadline;
    }

    /**
     * Check if the deadline has been exceeded
     */
    public function isDeadlineExceeded(): bool
    {
        if ($this->deadline === null) {
            return false;
        }
        return microtime(true) > $this->deadline;
    }

    /**
     * Get remaining time until deadline in seconds
     */
    public function getRemainingTime(): ?float
    {
        if ($this->deadline === null) {
            return null;
        }
        return max(0, $this->deadline - microtime(true));
    }

    /**
     * Cancel the context
     */
    public function cancel(): void
    {
        $this->cancelled = true;
    }

    /**
     * Check if context is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Set a context value
     */
    public function setValue(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    /**
     * Get a context value
     */
    public function getValue(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /**
     * Get authorization token from metadata
     */
    public function getAuthToken(): ?string
    {
        $auth = $this->getMetadataValue('authorization');
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return $auth;
    }

    /**
     * Get request ID from metadata
     */
    public function getRequestId(): ?string
    {
        return $this->getMetadataValue('x-request-id') 
            ?? $this->getMetadataValue('grpc-request-id');
    }

    /**
     * Create a child context with additional metadata
     */
    public function withMetadata(array $metadata): self
    {
        $child = clone $this;
        $child->metadata = array_merge($this->metadata, $metadata);
        return $child;
    }

    /**
     * Create a child context with a deadline
     */
    public function withDeadline(float $deadline): self
    {
        $child = clone $this;
        $child->deadline = $deadline;
        return $child;
    }

    /**
     * Create a child context with a timeout
     */
    public function withTimeout(float $seconds): self
    {
        return $this->withDeadline(microtime(true) + $seconds);
    }
}
