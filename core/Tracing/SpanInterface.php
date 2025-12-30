<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing;

/**
 * Interface for a span in a distributed trace
 * 
 * A span represents a single operation within a trace
 */
interface SpanInterface
{
    /**
     * Get the span ID
     */
    public function getSpanId(): string;

    /**
     * Get the trace ID
     */
    public function getTraceId(): string;

    /**
     * Get the span context
     */
    public function getContext(): SpanContextInterface;

    /**
     * Get the operation name
     */
    public function getOperationName(): string;

    /**
     * Set the operation name
     */
    public function setOperationName(string $name): self;

    /**
     * Set a single attribute
     */
    public function setAttribute(string $key, mixed $value): self;

    /**
     * Set multiple attributes
     */
    public function setAttributes(array $attributes): self;

    /**
     * Get all attributes
     */
    public function getAttributes(): array;

    /**
     * Add an event to the span
     */
    public function addEvent(string $name, array $attributes = [], ?float $timestamp = null): self;

    /**
     * Get all events
     */
    public function getEvents(): array;

    /**
     * Set the span status
     */
    public function setStatus(SpanStatus $status, ?string $description = null): self;

    /**
     * Get the span status
     */
    public function getStatus(): SpanStatus;

    /**
     * Record an exception
     */
    public function recordException(\Throwable $exception, array $attributes = []): self;

    /**
     * End the span
     */
    public function end(?float $endTime = null): void;

    /**
     * Check if the span has ended
     */
    public function hasEnded(): bool;

    /**
     * Get the start time in microseconds
     */
    public function getStartTime(): float;

    /**
     * Get the end time in microseconds (null if not ended)
     */
    public function getEndTime(): ?float;

    /**
     * Get the duration in seconds (null if not ended)
     */
    public function getDuration(): ?float;

    /**
     * Get the parent span ID (null if root span)
     */
    public function getParentSpanId(): ?string;

    /**
     * Check if this is a root span
     */
    public function isRoot(): bool;

    /**
     * Convert span to array for export
     */
    public function toArray(): array;
}
