<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing\Propagation;

use HybridPHP\Core\Tracing\SpanContext;
use HybridPHP\Core\Tracing\SpanContextInterface;

/**
 * Jaeger propagator
 * 
 * Implements Jaeger's native trace context propagation format
 * @see https://www.jaegertracing.io/docs/1.21/client-libraries/#propagation-format
 */
class JaegerPropagator implements PropagatorInterface
{
    public const UBER_TRACE_ID = 'uber-trace-id';
    public const JAEGER_DEBUG_ID = 'jaeger-debug-id';
    public const JAEGER_BAGGAGE_PREFIX = 'uberctx-';

    public function fields(): array
    {
        return [self::UBER_TRACE_ID, self::JAEGER_DEBUG_ID];
    }

    public function inject(SpanContextInterface $context, array &$carrier): void
    {
        if (!$context->isValid()) {
            return;
        }

        // Format: {trace-id}:{span-id}:{parent-span-id}:{flags}
        // Note: parent-span-id is deprecated and set to 0
        $flags = $context->isSampled() ? 1 : 0;
        
        $carrier[self::UBER_TRACE_ID] = sprintf(
            '%s:%s:0:%x',
            $context->getTraceId(),
            $context->getSpanId(),
            $flags
        );
    }

    public function extract(array $carrier): ?SpanContextInterface
    {
        $uberTraceId = $this->getHeader($carrier, self::UBER_TRACE_ID);
        
        if ($uberTraceId === null) {
            return null;
        }

        return $this->parseUberTraceId($uberTraceId);
    }

    /**
     * Parse uber-trace-id header
     */
    private function parseUberTraceId(string $uberTraceId): ?SpanContextInterface
    {
        // URL decode if necessary
        $uberTraceId = urldecode($uberTraceId);

        // Format: {trace-id}:{span-id}:{parent-span-id}:{flags}
        $parts = explode(':', $uberTraceId);

        if (count($parts) !== 4) {
            return null;
        }

        [$traceId, $spanId, $parentSpanId, $flags] = $parts;

        // Validate and normalize trace ID
        $traceId = $this->normalizeTraceId($traceId);
        if ($traceId === null) {
            return null;
        }

        // Validate span ID
        $spanId = $this->normalizeSpanId($spanId);
        if ($spanId === null) {
            return null;
        }

        // Parse flags
        $flagsInt = hexdec($flags);
        $sampled = ($flagsInt & 0x01) === 0x01;

        return new SpanContext(
            $traceId,
            $spanId,
            $sampled ? SpanContext::TRACE_FLAG_SAMPLED : SpanContext::TRACE_FLAG_NONE,
            null,
            true
        );
    }

    /**
     * Normalize trace ID to 32 hex characters
     */
    private function normalizeTraceId(string $traceId): ?string
    {
        // Remove leading zeros for validation
        $traceId = ltrim($traceId, '0') ?: '0';
        
        // Jaeger supports both 64-bit and 128-bit trace IDs
        if (!ctype_xdigit($traceId)) {
            return null;
        }

        $len = strlen($traceId);
        if ($len > 32) {
            return null;
        }

        // Pad to 32 characters
        return str_pad($traceId, 32, '0', STR_PAD_LEFT);
    }

    /**
     * Normalize span ID to 16 hex characters
     */
    private function normalizeSpanId(string $spanId): ?string
    {
        // Remove leading zeros for validation
        $spanId = ltrim($spanId, '0') ?: '0';
        
        if (!ctype_xdigit($spanId)) {
            return null;
        }

        $len = strlen($spanId);
        if ($len > 16) {
            return null;
        }

        // Pad to 16 characters
        return str_pad($spanId, 16, '0', STR_PAD_LEFT);
    }

    /**
     * Get header value from carrier (case-insensitive)
     */
    private function getHeader(array $carrier, string $name): ?string
    {
        $name = strtolower($name);
        
        foreach ($carrier as $key => $value) {
            if (strtolower($key) === $name) {
                return is_array($value) ? $value[0] : $value;
            }
        }

        return null;
    }
}
