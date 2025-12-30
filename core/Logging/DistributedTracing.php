<?php
namespace HybridPHP\Core\Logging;

/**
 * Distributed Tracing for tracking requests across services
 */
class DistributedTracing
{
    private static ?string $traceId = null;
    private static ?string $spanId = null;
    private static ?string $parentSpanId = null;
    private static array $spans = [];
    private static array $baggage = [];

    /**
     * Start a new trace
     */
    public static function startTrace(string $operationName = 'request'): string
    {
        self::$traceId = self::generateId();
        self::$spanId = self::generateId();
        self::$parentSpanId = null;
        
        self::startSpan($operationName);
        
        return self::$traceId;
    }

    /**
     * Start a new span
     */
    public static function startSpan(string $operationName, array $tags = []): string
    {
        $spanId = self::generateId();
        $startTime = microtime(true);
        
        $span = [
            'trace_id' => self::$traceId,
            'span_id' => $spanId,
            'parent_span_id' => self::$spanId,
            'operation_name' => $operationName,
            'start_time' => $startTime,
            'tags' => $tags,
            'logs' => [],
            'status' => 'active',
        ];
        
        self::$spans[$spanId] = $span;
        
        // Update current span
        self::$parentSpanId = self::$spanId;
        self::$spanId = $spanId;
        
        return $spanId;
    }

    /**
     * Finish current span
     */
    public static function finishSpan(array $tags = []): void
    {
        if (!self::$spanId || !isset(self::$spans[self::$spanId])) {
            return;
        }
        
        $span = &self::$spans[self::$spanId];
        $span['end_time'] = microtime(true);
        $span['duration'] = $span['end_time'] - $span['start_time'];
        $span['status'] = 'finished';
        
        if (!empty($tags)) {
            $span['tags'] = array_merge($span['tags'], $tags);
        }
        
        // Restore parent span
        self::$spanId = self::$parentSpanId;
        if (self::$spanId && isset(self::$spans[self::$spanId])) {
            self::$parentSpanId = self::$spans[self::$spanId]['parent_span_id'];
        }
    }

    /**
     * Add log to current span
     */
    public static function logToSpan(string $message, array $fields = []): void
    {
        if (!self::$spanId || !isset(self::$spans[self::$spanId])) {
            return;
        }
        
        self::$spans[self::$spanId]['logs'][] = [
            'timestamp' => microtime(true),
            'message' => $message,
            'fields' => $fields,
        ];
    }

    /**
     * Add tag to current span
     */
    public static function setTag(string $key, $value): void
    {
        if (!self::$spanId || !isset(self::$spans[self::$spanId])) {
            return;
        }
        
        self::$spans[self::$spanId]['tags'][$key] = $value;
    }

    /**
     * Set baggage item (propagated across spans)
     */
    public static function setBaggage(string $key, string $value): void
    {
        self::$baggage[$key] = $value;
    }

    /**
     * Get baggage item
     */
    public static function getBaggage(string $key): ?string
    {
        return self::$baggage[$key] ?? null;
    }

    /**
     * Get current trace ID
     */
    public static function getTraceId(): ?string
    {
        return self::$traceId;
    }

    /**
     * Get current span ID
     */
    public static function getSpanId(): ?string
    {
        return self::$spanId;
    }

    /**
     * Get all spans for current trace
     */
    public static function getSpans(): array
    {
        return self::$spans;
    }

    /**
     * Get current span
     */
    public static function getCurrentSpan(): ?array
    {
        return self::$spanId ? (self::$spans[self::$spanId] ?? null) : null;
    }

    /**
     * Extract trace context from headers
     */
    public static function extractFromHeaders(array $headers): void
    {
        // Support for various tracing header formats
        
        // Jaeger format
        if (isset($headers['uber-trace-id'])) {
            self::parseUberTraceId($headers['uber-trace-id']);
        }
        // B3 format (Zipkin)
        elseif (isset($headers['x-b3-traceid'])) {
            self::parseB3Headers($headers);
        }
        // W3C Trace Context
        elseif (isset($headers['traceparent'])) {
            self::parseW3CTraceParent($headers['traceparent']);
        }
        // Custom format
        elseif (isset($headers['x-trace-id'])) {
            self::$traceId = $headers['x-trace-id'];
            self::$spanId = $headers['x-span-id'] ?? self::generateId();
            self::$parentSpanId = $headers['x-parent-span-id'] ?? null;
        }
    }

    /**
     * Inject trace context into headers
     */
    public static function injectIntoHeaders(): array
    {
        if (!self::$traceId) {
            return [];
        }
        
        return [
            'x-trace-id' => self::$traceId,
            'x-span-id' => self::$spanId,
            'x-parent-span-id' => self::$parentSpanId,
            // W3C Trace Context format
            'traceparent' => sprintf('00-%s-%s-01', self::$traceId, self::$spanId),
        ];
    }

    /**
     * Parse Uber (Jaeger) trace ID format
     */
    private static function parseUberTraceId(string $traceId): void
    {
        $parts = explode(':', $traceId);
        if (count($parts) >= 2) {
            self::$traceId = $parts[0];
            self::$spanId = $parts[1];
            self::$parentSpanId = $parts[2] ?? null;
        }
    }

    /**
     * Parse B3 (Zipkin) headers
     */
    private static function parseB3Headers(array $headers): void
    {
        self::$traceId = $headers['x-b3-traceid'];
        self::$spanId = $headers['x-b3-spanid'] ?? self::generateId();
        self::$parentSpanId = $headers['x-b3-parentspanid'] ?? null;
    }

    /**
     * Parse W3C Trace Context traceparent header
     */
    private static function parseW3CTraceParent(string $traceparent): void
    {
        $parts = explode('-', $traceparent);
        if (count($parts) === 4) {
            self::$traceId = $parts[1];
            self::$spanId = $parts[2];
        }
    }

    /**
     * Generate a unique ID
     */
    private static function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Reset tracing context
     */
    public static function reset(): void
    {
        self::$traceId = null;
        self::$spanId = null;
        self::$parentSpanId = null;
        self::$spans = [];
        self::$baggage = [];
    }

    /**
     * Get tracing context for logging
     */
    public static function getContext(): array
    {
        return [
            'trace_id' => self::$traceId,
            'span_id' => self::$spanId,
            'parent_span_id' => self::$parentSpanId,
            'baggage' => self::$baggage,
        ];
    }

    /**
     * Create a child tracer for async operations
     */
    public static function createChildContext(): array
    {
        return [
            'trace_id' => self::$traceId,
            'parent_span_id' => self::$spanId,
            'baggage' => self::$baggage,
        ];
    }

    /**
     * Restore context from child tracer
     */
    public static function restoreContext(array $context): void
    {
        self::$traceId = $context['trace_id'];
        self::$parentSpanId = $context['parent_span_id'];
        self::$baggage = $context['baggage'] ?? [];
        self::$spanId = self::generateId();
    }
}