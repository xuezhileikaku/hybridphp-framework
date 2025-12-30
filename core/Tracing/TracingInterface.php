<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing;

/**
 * Interface for distributed tracing implementations
 * 
 * Provides OpenTelemetry-compatible tracing API for distributed systems
 */
interface TracingInterface
{
    /**
     * Start a new trace with a root span
     */
    public function startTrace(string $operationName, array $attributes = []): SpanInterface;

    /**
     * Start a new span within the current trace
     */
    public function startSpan(string $operationName, array $attributes = [], ?SpanContextInterface $parentContext = null): SpanInterface;

    /**
     * Get the current active span
     */
    public function getCurrentSpan(): ?SpanInterface;

    /**
     * Get the current trace context
     */
    public function getContext(): ?SpanContextInterface;

    /**
     * Extract trace context from carrier (headers, etc.)
     */
    public function extract(array $carrier): ?SpanContextInterface;

    /**
     * Inject trace context into carrier (headers, etc.)
     */
    public function inject(array &$carrier): void;

    /**
     * Flush all pending spans to the exporter
     */
    public function flush(): void;

    /**
     * Shutdown the tracer
     */
    public function shutdown(): void;
}
