<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing;

/**
 * Interface for span context
 * 
 * Contains the identifiers and flags needed to propagate trace context
 */
interface SpanContextInterface
{
    /**
     * Get the trace ID
     */
    public function getTraceId(): string;

    /**
     * Get the span ID
     */
    public function getSpanId(): string;

    /**
     * Get the trace flags
     */
    public function getTraceFlags(): int;

    /**
     * Get the trace state
     */
    public function getTraceState(): ?string;

    /**
     * Check if the context is valid
     */
    public function isValid(): bool;

    /**
     * Check if the context is remote (extracted from carrier)
     */
    public function isRemote(): bool;

    /**
     * Check if the trace is sampled
     */
    public function isSampled(): bool;

    /**
     * Create a new context with updated span ID
     */
    public function withSpanId(string $spanId): self;

    /**
     * Convert to array for serialization
     */
    public function toArray(): array;
}
